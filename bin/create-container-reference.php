<?php

declare(strict_types=1);

/** Sanitizes two Symfony debug:container JSON views into one dev reference. */
$paths = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(types|services)=(.+)$/D', $argument, $match) && !isset($paths[$match[1]])) $paths[$match[1]] = $match[2];
    else { fwrite(STDERR, "Usage: php bin/create-container-reference.php --types=TYPES.json --services=SERVICES.json\n"); exit(2); }
}

try {
    if (count($paths) !== 2) throw new InvalidArgumentException('Both Symfony debug views are required.');
    $views = [];
    $hashes = [];
    foreach (['types', 'services'] as $kind) {
        $path = $paths[$kind];
        if (is_link($path) || !is_file($path) || ($size = filesize($path)) === false || $size > 67_108_864) {
            throw new RuntimeException('Missing, linked or oversized ' . $kind . ' debug view.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== $size) throw new RuntimeException('Changed ' . $kind . ' debug view.');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['definitions'], $data['aliases'])
            || !is_array($data['definitions']) || !is_array($data['aliases'])
            || count($data['definitions']) + count($data['aliases']) > 100_000) {
            throw new UnexpectedValueException('Unsupported Symfony ' . $kind . ' debug view.');
        }
        $views[$kind] = $data;
        $hashes[$kind] = hash('sha256', $bytes);
    }
    $definitions = $aliases = [];
    foreach (['types', 'services'] as $kind) {
        foreach ($views[$kind]['definitions'] as $id => $definition) {
            $class = is_array($definition) ? $definition['class'] ?? null : null;
            if (!is_string($id) || $id === '' || !is_string($class) && $class !== null) throw new UnexpectedValueException('Invalid service definition.');
            $arguments = [];
            $rawArguments = is_array($definition) ? $definition['arguments'] ?? [] : [];
            if (!is_array($rawArguments) || !array_is_list($rawArguments)) {
                throw new UnexpectedValueException('Unsupported constructor arguments for ' . $id);
            }
            if (count($rawArguments) > 128) throw new UnexpectedValueException('Too many constructor arguments for ' . $id);
            foreach ($rawArguments as $argument) {
                $target = is_array($argument) && ($argument['type'] ?? null) === 'service' ? $argument['id'] ?? null : null;
                $arguments[] = is_string($target) && $target !== '' ? $target : null;
            }
            if (isset($definitions[$id]) && $definitions[$id]['class'] !== $class && $class !== null && $definitions[$id]['class'] !== null) {
                throw new UnexpectedValueException('Conflicting class for service ' . $id);
            }
            if (isset($definitions[$id]) && $arguments !== [] && $definitions[$id]['arguments'] !== [] && $arguments !== $definitions[$id]['arguments']) {
                throw new UnexpectedValueException('Conflicting constructor arguments for service ' . $id);
            }
            $definitions[$id] = [
                'class' => $class ?? $definitions[$id]['class'] ?? null,
                'arguments' => $arguments !== [] ? $arguments : ($definitions[$id]['arguments'] ?? []),
            ];
        }
        foreach ($views[$kind]['aliases'] as $id => $alias) {
            $target = is_array($alias) ? $alias['service'] ?? null : null;
            if (!is_string($id) || $id === '' || !is_string($target) || $target === '') throw new UnexpectedValueException('Invalid service alias.');
            if (isset($aliases[$id]) && $aliases[$id] !== $target) throw new UnexpectedValueException('Conflicting alias ' . $id);
            $aliases[$id] = $target;
        }
    }
    foreach ($aliases as $id => $_) if (isset($definitions[$id])) throw new UnexpectedValueException('Service and alias share ID ' . $id);
    ksort($definitions, SORT_STRING);
    ksort($aliases, SORT_STRING);
    $reference = [
        'schema_version' => '2',
        'environment' => 'dev',
        'scope' => 'compiled_container_autowiring',
        'definitions' => $definitions,
        'aliases' => $aliases,
        'source_sha256' => hash('sha256', $hashes['types'] . ':' . $hashes['services']),
    ];
    $json = json_encode($reference, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > 16_777_216) throw new RuntimeException('Sanitized container reference exceeds 16 MiB.');
    echo $json . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
