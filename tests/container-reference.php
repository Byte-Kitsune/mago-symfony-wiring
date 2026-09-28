<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ContainerReferenceLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/mago-wiring-reference-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
$types = $root . '/types.json';
$services = $root . '/services.json';
$reference = $root . '/config/container-reference.dev.json';
$run = static function () use ($types, $services): array {
    $command = [PHP_BINARY, dirname(__DIR__) . '/bin/create-container-reference.php', '--types=' . $types, '--services=' . $services];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Reference exporter did not start.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};

try {
    // Symfony's --types view contains automatic aliases but can omit the
    // concrete definition behind a named service ID. The ordinary view fills
    // that gap; the exporter strips scalar values and retains only service IDs.
    file_put_contents($types, json_encode([
        'definitions' => [
            'App\\Controller\\ReportController' => ['class' => 'App\\Controller\\ReportController', 'arguments' => [['type' => 'service', 'id' => 'app.special_reader'], 'private value']],
            'App\\Service\\DefaultReader' => ['class' => 'App\\Service\\DefaultReader'],
        ],
        'aliases' => [
            'App\\Contract\\Reader' => ['service' => 'App\\Service\\DefaultReader'],
            'App\\Contract\\Reader $specialReader' => ['service' => 'app.special_reader'],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($services, json_encode([
        'definitions' => ['app.special_reader' => ['class' => 'App\\Service\\SpecialReader', 'arguments' => ['secret value']]],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    [$exit, $output, $error] = $run();
    if ($exit !== 0 || $error !== '' || str_contains($output, 'private value') || str_contains($output, 'secret value')) {
        throw new RuntimeException('Container exporter did not sanitize both views: ' . $error);
    }
    file_put_contents($reference, $output);
    $loader = new ContainerReferenceLoader($root, 'config/container-reference.dev.json');
    $map = $loader->load();
    $bindings = $map->classBindings();
    if ($map->incomplete !== [] || ($bindings['App\\Contract\\Reader'] ?? null) !== 'App\\Service\\DefaultReader'
        || ($bindings['App\\Contract\\Reader $specialReader'] ?? null) !== 'App\\Service\\SpecialReader'
        || count($map->hashes) !== 1) throw new RuntimeException('Compiled-container aliases were not resolved.');
    $constructor = $loader->constructorClassBindings();
    if (($constructor['App\\Controller\\ReportController'] ?? null) !== [0 => 'App\\Service\\SpecialReader', 1 => null]) {
        throw new RuntimeException('Constructor service references were not resolved by position.');
    }

    file_put_contents($services, json_encode([
        'definitions' => ['App\\Service\\DefaultReader' => ['class' => 'App\\Service\\OtherReader']],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    [$exit] = $run();
    if ($exit !== 2) throw new RuntimeException('Conflicting container views were accepted.');

    $invalid = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    $invalid['environment'] = 'test';
    file_put_contents($reference, json_encode($invalid, JSON_THROW_ON_ERROR));
    try {
        (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
        throw new RuntimeException('A non-dev reference was accepted.');
    } catch (UnexpectedValueException) {}

    unlink($reference);
    symlink($types, $reference);
    try {
        (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
        throw new RuntimeException('A linked reference was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'A linked reference was accepted.') throw $error;
    }
} finally {
    if (is_file($reference) || is_link($reference)) unlink($reference);
    if (is_file($services)) unlink($services);
    if (is_file($types)) unlink($types);
    rmdir($root . '/config');
    rmdir($root);
}
echo "Compiled container reference checks passed\n";
