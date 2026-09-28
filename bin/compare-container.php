<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ContainerParity;
use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) $autoload = dirname(__DIR__, 3) . '/autoload.php';
require $autoload;

$root = null;
$files = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) $root = substr($argument, 7);
    elseif (str_starts_with($argument, '--service-file=')) $files[] = substr($argument, 15);
    else {
        fwrite(STDERR, "Usage: php bin/compare-container.php --root=PROJECT --service-file=FILE [--service-file=FILE ...] < debug-container.json\n");
        exit(2);
    }
}

try {
    if ($root === null || $root === '' || $files === []) throw new InvalidArgumentException('A project root and ordered service files are required.');
    $raw = stream_get_contents(STDIN, 67_108_865);
    if ($raw === false || strlen($raw) > 67_108_864) throw new RuntimeException('Container JSON exceeds 64 MiB.');
    $container = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($container) || !isset($container['definitions'], $container['aliases']) || !is_array($container['definitions']) || !is_array($container['aliases'])) {
        throw new UnexpectedValueException('Expected Symfony debug:container JSON with definitions and aliases.');
    }
    $map = (new ServiceConfigLoader($root, $files))->load();
    $result = ContainerParity::compare($map, $container);
    $result['scope'] = 'selected_service_ids';
    $result['configuration_hashes'] = $map->hashes;
    $result['container_sha256'] = hash('sha256', $raw);
    $result['incomplete'] = $map->incomplete;
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(match ($result['status']) { 'pass' => 0, 'mismatch' => 1, default => 2 });
} catch (Throwable $error) {
    echo json_encode(['status' => 'incomplete', 'scope' => 'selected_service_ids', 'checked' => 0, 'mismatch_count' => 0, 'truncated' => false, 'mismatches' => [], 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit(2);
}
