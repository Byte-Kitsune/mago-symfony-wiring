<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

/** Compares selected literal service identities with Symfony's debug container. */
final class ContainerParity
{
    /**
     * @param array{definitions: array<string, array{class?: mixed}>, aliases: array<string, array{service?: mixed}>} $container
     * @return array{status: string, checked: int, mismatch_count: int, truncated: bool, mismatches: list<array{id: string, reason: string, expected: ?string, actual: ?string}>}
     */
    public static function compare(ServiceMap $map, array $container): array
    {
        if ($map->incomplete !== []) {
            return ['status' => 'incomplete', 'checked' => 0, 'mismatch_count' => 0, 'truncated' => false, 'mismatches' => []];
        }

        $definitions = $container['definitions'];
        $aliases = $container['aliases'];
        $mismatches = [];
        $mismatchCount = 0;
        $ids = array_unique([...array_keys($map->services), ...array_keys($map->aliases)]);
        sort($ids, SORT_STRING);
        if ($ids === []) {
            return ['status' => 'incomplete', 'checked' => 0, 'mismatch_count' => 0, 'truncated' => false, 'mismatches' => []];
        }
        foreach ($ids as $id) {
            $expectedId = $map->resolveId($id);
            $expectedClass = $expectedId === null ? null : $map->services[$expectedId]['class'];
            $actualId = self::resolveId($id, $definitions, $aliases);
            $actualClass = $actualId === null ? null : ($definitions[$actualId]['class'] ?? null);
            if (!is_string($actualClass) || $actualClass === '') $actualClass = null;

            if ($expectedId === null || $expectedClass === null) {
                $mismatch = ['id' => $id, 'reason' => 'static-binding-unresolved', 'expected' => $expectedId, 'actual' => $actualId];
            } elseif ($actualId === null) {
                $mismatch = ['id' => $id, 'reason' => 'missing-in-container', 'expected' => $expectedId, 'actual' => null];
            } elseif ($expectedId !== $actualId) {
                $mismatch = ['id' => $id, 'reason' => 'different-service-id', 'expected' => $expectedId, 'actual' => $actualId];
            } elseif (ltrim($expectedClass, '\\') !== ltrim($actualClass ?? '', '\\')) {
                $mismatch = ['id' => $id, 'reason' => 'different-class', 'expected' => $expectedClass, 'actual' => $actualClass];
            } else {
                continue;
            }
            ++$mismatchCount;
            if (count($mismatches) < 1000) $mismatches[] = $mismatch;
        }

        return ['status' => $mismatchCount === 0 ? 'pass' : 'mismatch', 'checked' => count($ids), 'mismatch_count' => $mismatchCount, 'truncated' => $mismatchCount > count($mismatches), 'mismatches' => $mismatches];
    }

    /** @param array<string, array{class?: mixed}> $definitions
     *  @param array<string, array{service?: mixed}> $aliases
     */
    private static function resolveId(string $id, array $definitions, array $aliases): ?string
    {
        $seen = [];
        for ($depth = 0; $depth < 32; ++$depth) {
            if (isset($seen[$id])) return null;
            $seen[$id] = true;
            if (!isset($aliases[$id])) return isset($definitions[$id]) ? $id : null;
            $target = $aliases[$id]['service'] ?? null;
            if (!is_string($target) || $target === '') return null;
            $id = $target;
        }
        return null;
    }
}
