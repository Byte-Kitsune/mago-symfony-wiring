<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

/** Reads only explicit shared/dev service files; PHP configuration is parsed, never run. */
final class ServiceConfigLoader
{
    /** @param list<string> $files Ordered checkout-relative shared/dev service files. */
    public function __construct(private readonly string $root, private readonly array $files) {}

    public function load(): ServiceMap
    {
        $root = realpath($this->root);
        if ($root === false) {
            throw new \InvalidArgumentException('Service configuration root does not exist.');
        }
        $services = $aliases = $hashes = [];
        $incomplete = [];
        if (count($this->files) > 64) {
            throw new \InvalidArgumentException('At most 64 service files are supported.');
        }
        foreach ($this->files as $file) {
            if (!self::allowedPath($file)) {
                throw new \InvalidArgumentException('Unsafe or non-dev service path: ' . $file);
            }
            $path = $root . '/' . $file;
            if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
                $incomplete[] = $file . ': missing or linked file';
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size > 1_048_576) {
                $incomplete[] = $file . ': file exceeds one MiB';
                continue;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                $incomplete[] = $file . ': unreadable file';
                continue;
            }
            $hashes[$file] = hash('sha256', $bytes);
            try {
                $entries = str_ends_with($file, '.php') ? $this->parsePhp($bytes) : $this->parseYaml($bytes);
            } catch (\Throwable $error) {
                $incomplete[] = $file . ': unsupported configuration (' . $error::class . ')';
                continue;
            }
            $defaults = $entries['_defaults'] ?? [];
            if (!is_array($defaults) || array_diff(array_keys($defaults), ['autowire']) !== [] || !is_bool($defaults['autowire'] ?? true)) {
                $incomplete[] = $file . ': unsupported _defaults';
                $defaults = [];
            }
            unset($entries['_defaults']);
            foreach ($entries as $id => $entry) {
                if (!is_string($id) || !is_array($entry) && !is_string($entry)) {
                    $incomplete[] = $file . ': unsupported service entry';
                    continue;
                }
                if (is_string($entry)) {
                    if (str_starts_with($entry, '@') && !str_starts_with($entry, '@@')) {
                        $aliases[$id] = substr($entry, 1);
                    } else {
                        $incomplete[] = $file . ': unsupported alias ' . $id;
                    }
                    unset($services[$id]);
                    continue;
                }
                if (isset($entry['alias']) && is_string($entry['alias'])) {
                    $aliases[$id] = ltrim($entry['alias'], '@');
                    unset($services[$id]);
                    continue;
                }
                if (array_diff(array_keys($entry), ['class', 'arguments', 'autowire']) !== []) {
                    $incomplete[] = $file . ': unsupported fields for ' . $id;
                    continue;
                }
                $class = $entry['class'] ?? null;
                $arguments = $entry['arguments'] ?? [];
                if ($class !== null && !is_string($class) || !is_array($arguments) || !is_bool($entry['autowire'] ?? true)) {
                    $incomplete[] = $file . ': unsupported definition for ' . $id;
                    continue;
                }
                $services[$id] = ['class' => $class, 'arguments' => $arguments, 'autowire' => $entry['autowire'] ?? $defaults['autowire'] ?? true, 'origin' => $file];
                unset($aliases[$id]);
            }
        }
        ksort($services);
        ksort($aliases);
        ksort($hashes);
        return new ServiceMap($services, $aliases, $incomplete, $hashes);
    }

    private static function allowedPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $path)) {
            return false;
        }
        if (!preg_match('/\.(yaml|php)$/', $path)) {
            return false;
        }
        return !preg_match('~(^|[./_-])(test|prod|staging)([./_-]|$)~i', $path);
    }

    /** @return array<string, mixed> */
    private function parseYaml(string $bytes): array
    {
        $document = Yaml::parse($bytes);
        if (!is_array($document)) {
            throw new \UnexpectedValueException('Expected YAML mapping.');
        }
        $services = $document['services'] ?? [];
        if (!is_array($services)) {
            throw new \UnexpectedValueException('Expected services mapping.');
        }
        if (isset($document['when@dev']['services'])) {
            if (!is_array($document['when@dev']['services'])) {
                throw new \UnexpectedValueException('Expected dev services mapping.');
            }
            $services = array_replace($services, $document['when@dev']['services']);
        }
        return $services;
    }

    /** @return array<string, mixed> */
    private function parsePhp(string $bytes): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($bytes);
        if ($statements === null) {
            throw new \UnexpectedValueException('Empty PHP configuration.');
        }
        $traverser = new NodeTraverser(new NameResolver());
        $statements = $traverser->traverse($statements);
        foreach ((new NodeFinder())->findInstanceOf($statements, Node\Stmt\Return_::class) as $statement) {
            if (!$statement instanceof Node\Stmt\Return_ || !$statement->expr instanceof Node\Expr\StaticCall) {
                continue;
            }
            $call = $statement->expr;
            if (!$call->class instanceof Node\Name || $call->class->getLast() !== 'App' || !$call->name instanceof Node\Identifier || $call->name->toString() !== 'config') {
                continue;
            }
            $root = $this->literal($call->args[0]->value ?? null);
            if (!is_array($root) || !isset($root['services']) || !is_array($root['services'])) {
                throw new \UnexpectedValueException('No literal services node.');
            }
            return $root['services'];
        }
        throw new \UnexpectedValueException('No literal App::config return.');
    }

    private function literal(?Node $node): mixed
    {
        if ($node instanceof Node\Scalar\String_) return $node->value;
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) { 'true' => true, 'false' => false, default => throw new \UnexpectedValueException('Unsupported constant.') };
        }
        if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier && $node->name->toString() === 'class' && $node->class instanceof Node\Name) {
            return ($node->class->getAttribute('resolvedName') ?? $node->class)->toString();
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->literal($node->left);
            $right = $this->literal($node->right);
            if (is_string($left) && is_string($right)) return $left . $right;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->getLast() === 'service' && count($node->args) === 1) {
            $id = $this->literal($node->args[0]->value);
            if (is_string($id)) return '@' . $id;
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) throw new \UnexpectedValueException('Array spread unsupported.');
                $key = $item->key === null ? count($result) : $this->literal($item->key);
                if (!is_string($key) && !is_int($key)) throw new \UnexpectedValueException('Dynamic key unsupported.');
                $result[$key] = $this->literal($item->value);
            }
            return $result;
        }
        throw new \UnexpectedValueException('Dynamic PHP expression unsupported.');
    }
}
