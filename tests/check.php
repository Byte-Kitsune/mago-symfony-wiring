<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$map = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.yaml', 'config/services.dev.yaml']))->load();
if ($map->resolveTarget('App\\FormatterInterface', 'textFormatter') !== 'App\\DevFormatter') {
    throw new RuntimeException('Dev override was not selected.');
}
if ($map->resolveTarget('App\\FormatterInterface', 'text.formatter') !== 'App\\DevFormatter') {
    throw new RuntimeException('Symfony Target name normalization was not applied.');
}
if ($map->resolveTarget('App\\FormatterInterface', 'missingFormatter') !== null) {
    throw new RuntimeException('Missing alias resolved.');
}
if (($map->classBindings()['App\\FormatterInterface $textFormatter'] ?? null) !== 'App\\DevFormatter') {
    throw new RuntimeException('Class bindings did not preserve named dev alias.');
}
if (($map->serviceClassBindings()['App\\FormatterInterface $textFormatter'] ?? null) !== 'App\\DevFormatter'
    || ($map->serviceClassBindings()['App\\FormatterInterface'] ?? null) !== 'App\\Formatter') {
    throw new RuntimeException('Service IDs did not resolve through shared and dev aliases.');
}
$phpMap = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.php']))->load();
if ($phpMap->resolveTarget('App\\FormatterInterface', 'textFormatter') !== 'formatter.named') {
    throw new RuntimeException('PHP Configurator alias was not resolved.');
}
if (($phpMap->serviceClassBindings()['formatter.named'] ?? null) !== 'App\\DevFormatter'
    || ($phpMap->serviceClassBindings()['App\\FormatterInterface $textFormatter'] ?? null) !== 'App\\DevFormatter') {
    throw new RuntimeException('PHP service ID alias was not resolved to a class.');
}
$incomplete = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/missing.yaml']))->load();
if ($incomplete->serviceClassBindings() !== []) throw new RuntimeException('Incomplete service files supplied class bindings.');
$noServices = (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/no-services.yaml']))->load();
if ($noServices->incomplete === []) throw new RuntimeException('Missing services node was accepted.');
try {
    new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.test.yaml']);
    (new ServiceConfigLoader(__DIR__ . '/corpus', ['config/services.test.yaml']))->load();
    throw new RuntimeException('Test environment should be rejected.');
} catch (InvalidArgumentException) {
}
echo "Symfony wiring checks passed\n";
