<?php

declare(strict_types=1);

namespace ByteKitsune\MagoNative;

/** Command-scoped transport adapter. Analyzed PHP is data and is never executed. */
final class Engine
{
    private static ?self $instance = null;
    private static int $nextSession = 1;
    private mixed $process;
    private array $pipes;
    private bool $busy = false;
    private bool $broken = false;
    private ?\Fiber $owner = null;
    private int $callbackDepth = 0;
    private array $waiters = [];

    public static function enabled(): bool
    {
        $backend = getenv('MAGO_EXTENSION_BACKEND');
        if ($backend !== false && $backend !== '' && !in_array($backend, ['php', 'rust'], true)) throw new \RuntimeException('MAGO_EXTENSION_BACKEND must be rust or php.');
        return $backend !== 'php';
    }

    public static function analyze(string $source): array
    {
        if (strlen($source) > 8 * 1024 * 1024) throw new \LengthException('Class reference source exceeds 8 MiB.');
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'analyze', 'source' => $source]);
    }

    public static function ast(string $source): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'ast', 'source' => $source]);
    }

    public static function cycles(array $adjacency): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'cycles', 'adjacency' => (object) $adjacency]);
    }

    public static function stats(): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'stats']);
    }

    private function __construct()
    {
        if (!class_exists(Runtime::class, false)) require_once __DIR__ . '/NativeRuntime.php';
        $path = Runtime::executable();
        $process = proc_open([$path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Native engine could not be started.');
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 60);
        try { $info = $this->exchange(['op' => 'info'], null); } catch (\Throwable $error) { throw new \RuntimeException('Native engine is incompatible; install engine ' . Runtime::VERSION . ' or update MAGO_NATIVE_ENGINE.', 0, $error); }
        if (($info['version'] ?? null) !== Runtime::VERSION) throw new \RuntimeException('Native engine version mismatch; install engine ' . Runtime::VERSION . ' or update MAGO_NATIVE_ENGINE.');
    }

    public static function walk(string $start, int $limit, bool $transitive, \Closure $callback): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'walk', 'start' => $start, 'limit' => $limit, 'transitive' => $transitive], $callback);
    }

    public static function beginNativeBudget(array $bindings, array $constructorBindings): string
    {
        self::$instance ??= new self();
        $positions = array_map(static fn (array $values): object => (object) $values, $constructorBindings);
        $session = (string) self::$nextSession++;
        self::$instance->request(['op' => 'init-budget', 'session' => $session, 'program' => (object) [], 'bindings' => (object) $bindings, 'constructor_bindings' => (object) $positions]);
        return $session;
    }

    public static function indexBudgetSource(string $session, string $file, string $source): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'index-budget-source', 'session' => $session, 'file' => $file, 'source' => $source]);
    }

    public static function indexBudgetNodes(string $session, string $file, array $nodes): array
    {
        self::$instance ??= new self();
        return self::$instance->request(['op' => 'index-budget-nodes', 'session' => $session, 'file' => $file, 'nodes' => $nodes]);
    }

    public static function initializeBudget(array $program, array $methods, array $traits, array $functions): void
    {
        self::$instance ??= new self();
        self::$instance->request(['op' => 'init-budget', 'program' => (object) $program]);
        foreach (['methods' => $methods, 'traits' => $traits, 'functions' => $functions] as $kind => $models) {
            foreach (array_chunk($models, 64, true) as $batch) self::$instance->request(['op' => 'add-budget-' . $kind, 'models' => (object) $batch]);
        }
    }

    public static function budget(string $class, string $method, bool $assumeScalar, \Closure $callback, ?string $function = null, string $session = 'legacy'): array
    {
        self::$instance ??= new self();
        $request = ['op' => 'budget', 'session' => $session, 'class' => $class, 'method' => $method, 'assume_scalar' => $assumeScalar];
        if ($function !== null) $request['function'] = $function;
        return self::$instance->request($request, $callback);
    }

    private function request(array $request, ?\Closure $callback = null): array
    {
        if ($this->broken) throw new \RuntimeException('Native engine session is unavailable after a transport failure.');
        if ($this->busy && $this->callbackDepth > 0 && \Fiber::getCurrent() === $this->owner) return $this->exchange($request, $callback);
        while ($this->busy) {
            $fiber = \Fiber::getCurrent();
            if ($fiber === null) throw new \RuntimeException('Native engine cannot be reentered outside a Fiber.');
            $this->waiters[] = $fiber;
            \Fiber::suspend();
        }
        $this->busy = true;
        $this->owner = \Fiber::getCurrent();
        try {
            return $this->exchange($request, $callback);
        } catch (\PhpParser\Error | \LengthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->broken = true;
            proc_terminate($this->process);
            throw $error;
        } finally {
            $this->busy = false;
            $this->owner = null;
            $next = array_shift($this->waiters);
            if ($next !== null) \Revolt\EventLoop::queue(static fn () => $next->resume());
        }
    }

    private function exchange(array $request, ?\Closure $callback): array
    {
        $bytes = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->pipes[0], substr($bytes, $offset));
            if ($written === false || $written === 0) throw new \RuntimeException('Native engine request failed.');
            $offset += $written;
        }
        fflush($this->pipes[0]);
        readResponse:
        $reply = '';
        do {
            $chunk = fgets($this->pipes[1], 8192);
            if ($chunk === false) throw new \RuntimeException('Native engine response failed or was truncated.');
            $reply .= $chunk;
            if (strlen($reply) > 128 * 1024 * 1024) throw new \RuntimeException('Native engine response exceeds 128 MiB.');
        } while (!str_ends_with($reply, "\n"));
        $response = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
        if (($response['schema'] ?? null) !== '1') throw new \RuntimeException('Native engine schema mismatch.');
        if (isset($response['callback'])) {
            if ($callback === null) throw new \RuntimeException('Unexpected native callback.');
            ++$this->callbackDepth;
            try { $value = $callback($response['callback']); } finally { --$this->callbackDepth; }
            $reply = json_encode(['callback_result' => $value], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($this->pipes[0], $reply) !== strlen($reply)) throw new \RuntimeException('Native callback response failed.');
            fflush($this->pipes[0]);
            goto readResponse;
        }
        if (isset($response['error'])) {
            if (str_contains($response['error'], 'exceeds')) throw new \LengthException($response['error']);
            throw new \PhpParser\Error($response['error']);
        }
        if (!is_array($response['result'] ?? null)) throw new \RuntimeException('Native engine result is invalid.');
        return $response['result'];
    }

    public function __destruct()
    {
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($this->process)) proc_close($this->process);
    }
}
