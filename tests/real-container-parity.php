<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;
use ByteKitsune\MagoSymfonyWiring\ContainerParity;
use ParityFixture\DefaultConsumer;
use ParityFixture\DevFormatter;
use ParityFixture\DefaultFormatter;
use ParityFixture\FormatterInterface;
use ParityFixture\TargetConsumer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/parity-fixture/src/Fixture.php';

$root = __DIR__ . '/parity-fixture';
$files = ['config/services.yaml', 'config/services.dev.yaml'];
$map = (new ServiceConfigLoader($root, $files))->load();
if ($map->incomplete !== []) throw new RuntimeException('Fixture service map is incomplete.');

$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator($root));
foreach ($files as $file) $loader->load($file);
$container->getDefinition(TargetConsumer::class)->setPublic(true);
$container->getDefinition(DefaultConsumer::class)->setPublic(true);
$container->compile();

$actualTarget = $container->get(TargetConsumer::class)->formatter::class;
$actualDefault = $container->get(DefaultConsumer::class)->formatter::class;
$targetId = $map->resolveTarget(FormatterInterface::class, 'text.formatter');
$defaultId = $map->resolveType(FormatterInterface::class);
if ($targetId === null || $defaultId === null) throw new RuntimeException('Fixture aliases did not resolve.');
if ($map->services[$targetId]['class'] !== $actualTarget || $actualTarget !== DevFormatter::class) {
    throw new RuntimeException('Named target differs from compiled Symfony container.');
}
if ($map->services[$defaultId]['class'] !== $actualDefault || $actualDefault !== DefaultFormatter::class) {
    throw new RuntimeException('Default binding differs from compiled Symfony container.');
}

$debugContainer = new ContainerBuilder();
$debugLoader = new YamlFileLoader($debugContainer, new FileLocator($root));
foreach ($files as $file) $debugLoader->load($file);
$debugContainer->getCompilerPassConfig()->setRemovingPasses([]);
$debugContainer->getCompilerPassConfig()->setAfterRemovingPasses([]);
$debugContainer->compile();
$debug = ['definitions' => [], 'aliases' => []];
foreach ($debugContainer->getDefinitions() as $id => $definition) {
    $debug['definitions'][$id] = ['class' => $definition->getClass()];
}
foreach ($debugContainer->getAliases() as $id => $alias) {
    $debug['aliases'][$id] = ['service' => (string) $alias];
}
$parity = ContainerParity::compare($map, $debug);
if ($parity['status'] !== 'pass' || $parity['checked'] !== 6) {
    throw new RuntimeException('Selected service IDs differ from the real Symfony debug container: ' . json_encode($parity));
}
$empty = ContainerParity::compare(new \ByteKitsune\MagoSymfonyWiring\ServiceMap([], [], [], []), $debug);
if ($empty['status'] !== 'incomplete') throw new RuntimeException('Empty parity scope was accepted.');
$drifted = $debug;
$drifted['aliases'][FormatterInterface::class . ' $textFormatter']['service'] = DefaultFormatter::class;
$drift = ContainerParity::compare($map, $drifted);
if ($drift['status'] !== 'mismatch' || count($drift['mismatches']) !== 1 || $drift['mismatches'][0]['reason'] !== 'different-service-id') {
    throw new RuntimeException('Container drift was not detected.');
}

$runCli = static function (string $input) use ($root, $files): array {
    $command = [PHP_BINARY, dirname(__DIR__) . '/bin/compare-container.php', '--root=' . $root];
    foreach ($files as $file) $command[] = '--service-file=' . $file;
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start parity CLI.');
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($errors !== '') throw new RuntimeException('Parity CLI wrote unexpected stderr: ' . $errors);
    return [$exit, json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
};
[$exit, $report] = $runCli(json_encode($debug, JSON_THROW_ON_ERROR));
if ($exit !== 0 || $report['status'] !== 'pass' || $report['scope'] !== 'selected_service_ids' || count($report['configuration_hashes']) !== 2) {
    throw new RuntimeException('Parity CLI did not report a complete pass.');
}
[$exit, $report] = $runCli(json_encode($drifted, JSON_THROW_ON_ERROR));
if ($exit !== 1 || $report['status'] !== 'mismatch') throw new RuntimeException('Parity CLI did not reject container drift.');
[$exit, $report] = $runCli('{');
if ($exit !== 2 || $report['status'] !== 'incomplete') throw new RuntimeException('Parity CLI accepted invalid container JSON.');
echo "Compiled Symfony container parity fixture passed\n";
