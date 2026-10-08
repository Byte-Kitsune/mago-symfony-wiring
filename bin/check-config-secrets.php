<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\Security\ConfigSecretInspector;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) $autoload = dirname(__DIR__, 3) . '/autoload.php';
require $autoload;

$root = null;
$files = [];
$options = [];
try {
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) $root = substr($argument, 7);
        elseif (str_starts_with($argument, '--file=')) $files[] = substr($argument, 7);
        elseif (str_starts_with($argument, '--exclude=')) $options['excludePaths'][] = substr($argument, 10);
        elseif (str_starts_with($argument, '--sensitive-key=')) $options['sensitiveKeys'][] = substr($argument, 16);
        else throw new InvalidArgumentException('Unknown argument.');
    }
    if ($root === null || $root === '' || $files === []) throw new InvalidArgumentException('Explicit root and files are required.');
    $report = ConfigSecretInspector::inspectConfigFiles($root, $files, $options);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($report['incomplete'] !== [] ? 2 : ($report['issues'] !== [] ? 1 : 0));
} catch (Throwable) {
    // No exception details: parsers/user arguments can contain credentials.
    fwrite(STDERR, "Usage: php bin/check-config-secrets.php --root=PROJECT --file=RELATIVE [--file=RELATIVE ...] [--exclude=GLOB] [--sensitive-key=KEY]\n");
    exit(2);
}
