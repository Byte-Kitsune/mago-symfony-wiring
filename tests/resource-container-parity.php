<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ContainerParity;
use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;
use ResourceFixture\Consumer;
use ResourceFixture\Formatter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/resource-parity-fixture/src/FormatterInterface.php';
require __DIR__ . '/resource-parity-fixture/src/Formatter.php';
require __DIR__ . '/resource-parity-fixture/src/Consumer.php';

$root = __DIR__ . '/resource-parity-fixture';
$map = (new ServiceConfigLoader($root, ['config/services.yaml']))->load();
if ($map->incomplete !== [] || ($map->classBindings()['ResourceFixture\\FormatterInterface'] ?? null) !== Formatter::class) {
    throw new RuntimeException('Resource alias did not resolve to the fixture class.');
}

$container = new ContainerBuilder();
(new YamlFileLoader($container, new FileLocator($root)))->load('config/services.yaml');
$container->getDefinition(Consumer::class)->setPublic(true);
$container->getCompilerPassConfig()->setRemovingPasses([]);
$container->getCompilerPassConfig()->setAfterRemovingPasses([]);
$container->compile();
if ($container->get(Consumer::class)->formatter::class !== Formatter::class) {
    throw new RuntimeException('Symfony did not inject the resource-backed alias.');
}
$debug = ['definitions' => [], 'aliases' => []];
foreach ($container->getDefinitions() as $id => $definition) $debug['definitions'][$id] = ['class' => $definition->getClass()];
foreach ($container->getAliases() as $id => $alias) $debug['aliases'][$id] = ['service' => (string) $alias];
$parity = ContainerParity::compare($map, $debug);
if ($parity['status'] !== 'pass' || $parity['checked'] !== 2) {
    throw new RuntimeException('Resource-backed alias differs from compiled container: ' . json_encode($parity));
}
echo "Resource-backed Symfony container parity fixture passed\n";
