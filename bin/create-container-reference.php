<?php

declare(strict_types=1);

/** Sanitizes two Symfony debug:container JSON views into one dev reference. */
$paths = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(types|services)=(.+)$/D', $argument, $match) && !isset($paths[$match[1]])) $paths[$match[1]] = $match[2];
    else { fwrite(STDERR, "Usage: php bin/create-container-reference.php --types=TYPES.json --services=SERVICES.json\n"); exit(2); }
}

$parameterPositions = [];
$autoloadLoaded = false;
$normalizeArguments = static function (array $raw, ?string $class, string $id) use (&$parameterPositions, &$autoloadLoaded): array {
    if (count($raw) > 128) throw new UnexpectedValueException('Too many constructor arguments for ' . $id);
    if (array_is_list($raw)) {
        return array_map(static function (mixed $argument): ?string {
            $target = is_array($argument) && ($argument['type'] ?? null) === 'service' ? $argument['id'] ?? null : null;
            return is_string($target) && $target !== '' ? $target : null;
        }, $raw);
    }

    $namedServiceReference = false;
    foreach ($raw as $position => $argument) {
        if (!is_string($position) || !is_array($argument) || ($argument['type'] ?? null) !== 'service') continue;
        $namedServiceReference = true;
        break;
    }
    if ($namedServiceReference) {
        if ($class === null || $class === '') throw new UnexpectedValueException('Cannot resolve named constructor arguments for ' . $id);
        if (!$autoloadLoaded) {
            foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/autoload.php'] as $autoload) {
                if (is_file($autoload)) { require_once $autoload; break; }
            }
            $autoloadLoaded = true;
        }
        if (!isset($parameterPositions[$class])) {
            try {
                $constructor = (new ReflectionClass($class))->getConstructor();
            } catch (ReflectionException $error) {
                throw new UnexpectedValueException('Cannot reflect constructor for service ' . $id, 0, $error);
            }
            if ($constructor === null) throw new UnexpectedValueException('Service has no constructor for named argument: ' . $id);
            $positions = [];
            foreach ($constructor->getParameters() as $index => $parameter) $positions[$parameter->getName()] = $index;
            $parameterPositions[$class] = $positions;
        }
    }

    $bound = [];
    foreach ($raw as $position => $argument) {
        $isService = is_array($argument) && ($argument['type'] ?? null) === 'service';
        $target = $isService ? $argument['id'] ?? null : null;
        $target = is_string($target) && $target !== '' ? $target : null;
        if (is_string($position)) {
            // Scalar named arguments carry no service edge and need no position.
            if (!$isService) continue;
            $name = str_starts_with($position, '$') ? substr($position, 1) : $position;
            if ($name === '' || !array_key_exists($name, $parameterPositions[$class] ?? [])) {
                throw new UnexpectedValueException('Unknown named constructor argument for ' . $id);
            }
            $position = $parameterPositions[$class][$name];
        }
        if (!is_int($position) || $position < 0 || $position >= 128 || array_key_exists($position, $bound)) {
            throw new UnexpectedValueException('Unsupported constructor position for ' . $id);
        }
        $bound[$position] = $target;
    }
    if ($bound === []) return [];
    $arguments = array_fill(0, max(array_keys($bound)) + 1, null);
    foreach ($bound as $position => $target) $arguments[$position] = $target;
    return $arguments;
};

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
    $classes = [];
    foreach (['types', 'services'] as $kind) foreach ($views[$kind]['definitions'] as $id => $definition) {
        $class = is_array($definition) ? $definition['class'] ?? null : null;
        if (!is_string($id) || $id === '' || !is_array($definition) || !is_string($class) && $class !== null) {
            throw new UnexpectedValueException('Invalid service definition.');
        }
        if (isset($classes[$id]) && $class !== null && $classes[$id] !== $class) {
            throw new UnexpectedValueException('Conflicting class for service ' . $id);
        }
        if ($class !== null) $classes[$id] = $class;
    }
    $definitions = $aliases = [];
    foreach (['types', 'services'] as $kind) {
        foreach ($views[$kind]['definitions'] as $id => $definition) {
            $rawArguments = $definition['arguments'] ?? [];
            if (!is_array($rawArguments)) throw new UnexpectedValueException('Unsupported constructor arguments for ' . $id);
            $arguments = $normalizeArguments($rawArguments, $classes[$id] ?? null, $id);
            $previous = $definitions[$id]['arguments'] ?? [];
            if ($previous !== [] && $arguments !== []) {
                $merged = array_fill(0, max(count($previous), count($arguments)), null);
                foreach ($merged as $position => $_) {
                    $left = $previous[$position] ?? null;
                    $right = $arguments[$position] ?? null;
                    if ($left !== null && $right !== null && $left !== $right) {
                        throw new UnexpectedValueException('Conflicting constructor arguments for service ' . $id);
                    }
                    $merged[$position] = $left ?? $right;
                }
                $arguments = $merged;
            }
            $definitions[$id] = [
                'class' => $classes[$id] ?? null,
                'arguments' => $arguments !== [] ? $arguments : $previous,
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
