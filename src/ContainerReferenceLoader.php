<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

/** Reads a sanitized snapshot of Symfony's compiled dev service bindings. */
final class ContainerReferenceLoader implements ServiceMapLoader
{
    public const MAX_REFERENCE_BYTES = 64 * 1024 * 1024;

    private ?ServiceMap $cached = null;
    private ?array $constructorBindings = null;

    public function __construct(private readonly string $root, private readonly string $file) {}

    public function load(): ServiceMap
    {
        if ($this->cached !== null) return $this->cached;
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) throw new \InvalidArgumentException('Project root does not exist.');
        if ($this->file === '' || str_starts_with($this->file, '/') || str_contains($this->file, '\\')
            || str_contains($this->file, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $this->file)
            || !str_ends_with($this->file, '.json')) {
            throw new \InvalidArgumentException('Unsafe container reference path.');
        }
        $path = $root . '/' . $this->file;
        if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
            throw new \RuntimeException('Container reference is missing or linked.');
        }
        $size = filesize($path);
        if ($size === false || $size < 2) throw new \RuntimeException('Container reference is empty or unreadable.');
        if ($size > self::MAX_REFERENCE_BYTES) throw new \RuntimeException('Container reference exceeds 64 MiB.');
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== $size) throw new \RuntimeException('Container reference changed during read.');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['schema_version', 'environment', 'scope', 'definitions', 'aliases', 'source_sha256']
            || $data['schema_version'] !== '2' || $data['environment'] !== 'dev' || $data['scope'] !== 'compiled_container_autowiring'
            || !is_array($data['definitions']) || !is_array($data['aliases'])
            || !is_string($data['source_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $data['source_sha256'])
            || count($data['definitions']) + count($data['aliases']) > 100_000) {
            throw new \UnexpectedValueException('Invalid dev container reference.');
        }

        $services = [];
        foreach ($data['definitions'] as $id => $definition) {
            if (!self::id($id) || !is_array($definition) || array_keys($definition) !== ['class', 'arguments']
                || $definition['class'] !== null && !self::id($definition['class'])
                || !is_array($definition['arguments']) || !array_is_list($definition['arguments']) || count($definition['arguments']) > 128) {
                throw new \UnexpectedValueException('Invalid container service definition.');
            }
            foreach ($definition['arguments'] as $target) if ($target !== null && !self::id($target)) {
                throw new \UnexpectedValueException('Invalid container constructor reference.');
            }
            $services[$id] = ['class' => $definition['class'], 'arguments' => $definition['arguments'], 'autowire' => true, 'origin' => $this->file];
        }
        $aliases = [];
        foreach ($data['aliases'] as $id => $target) {
            if (!self::id($id) || !self::id($target) || isset($services[$id])) {
                throw new \UnexpectedValueException('Invalid container service alias.');
            }
            $aliases[$id] = $target;
        }
        ksort($services, SORT_STRING);
        ksort($aliases, SORT_STRING);
        return $this->cached = new ServiceMap($services, $aliases, [], [$this->file => hash('sha256', $bytes)]);
    }

    public function constructorClassBindings(): array
    {
        if ($this->constructorBindings !== null) return $this->constructorBindings;
        $map = $this->load();
        $candidates = [];
        foreach ($map->services as $service) {
            $owner = $service['class'];
            if ($owner === null) continue;
            $bindings = [];
            foreach ($service['arguments'] as $position => $target) {
                $resolved = $target === null ? null : $map->resolveId($target);
                $bindings[$position] = $resolved === null ? null : $map->services[$resolved]['class'];
            }
            $candidates[$owner][] = $bindings;
        }
        $result = [];
        foreach ($candidates as $owner => $definitions) {
            $positions = [];
            foreach ($definitions as $definition) foreach (array_keys($definition) as $position) $positions[$position] = true;
            foreach (array_keys($positions) as $position) {
                $classes = array_map(static fn (array $definition): ?string => $definition[$position] ?? null, $definitions);
                $result[$owner][$position] = count(array_unique($classes, SORT_REGULAR)) === 1 ? $classes[0] : null;
            }
        }
        ksort($result, SORT_STRING);
        return $this->constructorBindings = $result;
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }
}
