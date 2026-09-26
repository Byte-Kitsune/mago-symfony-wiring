<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

/** Literal, revision-bound service identities. Never represents a compiled container. */
final class ServiceMap
{
    /** @param array<string, array{class: ?string, arguments: array<int|string, mixed>, autowire: bool, origin: string}> $services
     *  @param array<string, string> $aliases
     *  @param list<string> $incomplete
     *  @param array<string, string> $hashes
     */
    public function __construct(
        public readonly array $services,
        public readonly array $aliases,
        public readonly array $incomplete,
        public readonly array $hashes,
    ) {}

    public function resolveId(string $id): ?string
    {
        if ($this->incomplete !== []) return null;
        $seen = [];
        for ($depth = 0; $depth < 32; ++$depth) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            if (!isset($this->aliases[$id])) {
                return isset($this->services[$id]) ? $id : null;
            }
            $id = $this->aliases[$id];
        }
        return null;
    }

    public function resolveTarget(string $type, string $target): ?string
    {
        return $this->resolveId(ltrim($type, '\\') . ' $' . ltrim($target, '$'));
    }

    public function resolveType(string $type): ?string
    {
        return $this->resolveId(ltrim($type, '\\'));
    }

    /** @return array<string, string> Type or "Type $target" to concrete service class. */
    public function classBindings(): array
    {
        if ($this->incomplete !== []) return [];
        $bindings = [];
        foreach ($this->aliases as $type => $_) {
            $id = $this->resolveId($type);
            $class = $id === null ? null : $this->services[$id]['class'];
            if ($class !== null) $bindings[$type] = $class;
        }
        foreach ($this->services as $id => $service) {
            if ($service['class'] !== null && !isset($bindings[$id])) $bindings[$id] = $service['class'];
        }
        ksort($bindings);
        return $bindings;
    }

    /** @return array<string, string> Exact service ID (including aliases) to declared class. */
    public function serviceClassBindings(): array
    {
        if ($this->incomplete !== []) return [];
        $bindings = [];
        foreach (array_keys($this->services + $this->aliases) as $name) {
            $id = $this->resolveId($name);
            $class = $id === null ? null : $this->services[$id]['class'];
            if ($class !== null) $bindings[$name] = $class;
        }
        ksort($bindings);
        return $bindings;
    }
}
