<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$map = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.yaml', 'config/services.dev.yaml']))->load();
if ($map->resolveTarget('App\\FormatterInterface', 'textFormatter') !== 'App\\DevFormatter') {
    throw new RuntimeException('Dev override was not selected.');
}
if ($map->resolveTarget('App\\FormatterInterface', 'missingFormatter') !== null) {
    throw new RuntimeException('Missing alias resolved.');
}
if (($map->classBindings()['App\\FormatterInterface $textFormatter'] ?? null) !== 'App\\DevFormatter') {
    throw new RuntimeException('Class bindings did not preserve named dev alias.');
}
$phpMap = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.php']))->load();
if ($phpMap->resolveTarget('App\\FormatterInterface', 'textFormatter') !== 'formatter.named') {
    throw new RuntimeException('PHP Configurator alias was not resolved.');
}
try {
    new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.test.yaml']);
    (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.test.yaml']))->load();
    throw new RuntimeException('Test environment should be rejected.');
} catch (InvalidArgumentException) {
}
echo "Symfony wiring checks passed\n";
