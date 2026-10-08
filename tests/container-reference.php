<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\ContainerReferenceLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/mago-wiring-reference-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
$types = $root . '/types.json';
$services = $root . '/services.json';
$reference = $root . '/config/container-reference.dev.json';
$run = static function (array $extra = [], ?string $cwd = null) use ($types, $services): array {
    $command = [PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__) . '/bin/create-container-reference.php', '--types=' . $types, '--services=' . $services, ...$extra];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd);
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

    // Symfony's two debug views can both contain a classless abstract template.
    // It is not a constructor owner, even when its arguments mention services.
    $typeView = json_decode(file_get_contents($types), true, 64, JSON_THROW_ON_ERROR);
    $typeView['definitions']['lexik_jwt_authentication.key_loader.abstract'] = [
        'class' => '', 'abstract' => true,
        'arguments' => [null, null, ['type' => 'service', 'id' => 'app.special_reader']],
    ];
    file_put_contents($types, json_encode($typeView, JSON_THROW_ON_ERROR));
    $serviceView = json_decode(file_get_contents($services), true, 64, JSON_THROW_ON_ERROR);
    $serviceView['definitions']['lexik_jwt_authentication.key_loader.abstract'] = [
        'class' => '', 'abstract' => true, 'arguments' => [null, null, null, null],
    ];
    file_put_contents($services, json_encode($serviceView, JSON_THROW_ON_ERROR));
    [$exit, $abstractOutput, $error] = $run();
    if ($exit !== 0 || $error !== '') throw new RuntimeException('Classless abstract service was rejected: ' . $error);
    $abstractReference = json_decode($abstractOutput, true, 64, JSON_THROW_ON_ERROR);
    if (($abstractReference['definitions']['lexik_jwt_authentication.key_loader.abstract'] ?? null)
        !== ['class' => null, 'arguments' => []]) throw new RuntimeException('Abstract service gained constructor bindings.');
    file_put_contents($reference, $abstractOutput);
    $abstractMap = (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
    if (isset($abstractMap->classBindings()['lexik_jwt_authentication.key_loader.abstract'])) {
        throw new RuntimeException('Abstract service gained a class binding.');
    }

    // Symfony may emit named arguments in arbitrary key order, including a
    // vendor service unrelated to the application's own constructor calls.
    $namedClass = Symfony\Component\DependencyInjection\Definition::class;
    $typeView = json_decode(file_get_contents($types), true, 64, JSON_THROW_ON_ERROR);
    $typeView['definitions']['twig.mime_body_renderer'] = ['class' => $namedClass, 'arguments' => [null, ['type' => 'service', 'id' => 'app.special_reader']]];
    file_put_contents($types, json_encode($typeView, JSON_THROW_ON_ERROR));
    file_put_contents($services, json_encode([
        'definitions' => [
            'app.special_reader' => ['class' => 'App\\Service\\SpecialReader'],
            'twig.mime_body_renderer' => ['class' => $namedClass, 'arguments' => [
                '$arguments' => ['type' => 'service', 'id' => 'app.special_reader'],
                '$class' => 'private scalar',
            ]],
            'sparse.service' => ['class' => $namedClass, 'arguments' => [1 => ['type' => 'service', 'id' => 'app.special_reader']]],
            'scalar.only' => ['class' => 'App\\UnloadedClass', 'arguments' => ['$secret' => 'private value']],
        ],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    [$exit, $namedOutput, $error] = $run();
    if ($exit !== 0 || $error !== '' || str_contains($namedOutput, 'private value') || str_contains($namedOutput, 'private scalar')) {
        throw new RuntimeException('Named constructor arguments were not safely exported: ' . $error);
    }
    $namedReference = json_decode($namedOutput, true, 64, JSON_THROW_ON_ERROR);
    if (($namedReference['definitions']['twig.mime_body_renderer']['arguments'] ?? null) !== [null, 'app.special_reader']
        || ($namedReference['definitions']['sparse.service']['arguments'] ?? null) !== [null, 'app.special_reader']
        || ($namedReference['definitions']['scalar.only']['arguments'] ?? null) !== []) {
        throw new RuntimeException('Named and sparse constructor references lost their positions.');
    }
    file_put_contents($reference, $namedOutput);
    $namedBindings = (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->constructorClassBindings();
    if (($namedBindings[$namedClass] ?? null) !== [0 => null, 1 => 'App\\Service\\SpecialReader']) {
        throw new RuntimeException('Named constructor binding was not resolved by the loader.');
    }

    // The extension can live in a separate tools/vendor tree. Reflection must
    // still see application classes through the app's own Composer autoloader.
    mkdir($root . '/vendor');
    file_put_contents($root . '/vendor/autoload.php', <<<'PHP'
<?php
namespace App\Fixture;
final class NamedConstructor {
    public function __construct(object $first, object $second) {}
}
PHP);
    file_put_contents($services, json_encode([
        'definitions' => [
            'app.special_reader' => ['class' => 'App\\Service\\SpecialReader'],
            'app.named' => ['class' => 'App\\Fixture\\NamedConstructor', 'arguments' => [
                '$second' => ['type' => 'service', 'id' => 'app.special_reader'],
            ]],
        ],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    foreach ([ [[], $root], [['--autoload=' . $root . '/vendor/autoload.php'], dirname(__DIR__)] ] as [$extra, $cwd]) {
        [$exit, $appOutput, $error] = $run($extra, $cwd);
        $appReference = $exit === 0 ? json_decode($appOutput, true, 64, JSON_THROW_ON_ERROR) : [];
        if ($exit !== 0 || $error !== '' || ($appReference['definitions']['app.named']['arguments'] ?? null) !== [null, 'app.special_reader']) {
            throw new RuntimeException('Application autoloader did not resolve named argument: ' . $error);
        }
    }
    [$exit, , $error] = $run(['--autoload=' . $root . '/missing.php']);
    if ($exit !== 2 || !str_contains($error, 'Application Composer autoloader is missing')) {
        throw new RuntimeException('Missing explicit application autoloader was accepted.');
    }

    file_put_contents($services, json_encode([
        'definitions' => ['twig.mime_body_renderer' => ['class' => $namedClass, 'arguments' => [
            '$missing' => ['type' => 'service', 'id' => 'app.special_reader'],
        ]]],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    [$exit, , $error] = $run();
    if ($exit !== 2 || !str_contains($error, 'Unknown named constructor argument')) {
        throw new RuntimeException('Unknown named service reference was silently misbound.');
    }

    file_put_contents($services, json_encode([
        'definitions' => ['unknown.service' => ['class' => 'App\\UnloadedClass', 'arguments' => [
            '$dependency' => ['type' => 'service', 'id' => 'app.special_reader'],
        ]]],
        'aliases' => [],
    ], JSON_THROW_ON_ERROR));
    [$exit, , $error] = $run();
    if ($exit !== 2 || !str_contains($error, 'Cannot reflect constructor')) {
        throw new RuntimeException('Unreflectable named service reference was silently dropped.');
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

    // Exercise export and load together with a sanitized service graph above
    // 16 MiB, while both raw debug views remain below the 64 MiB input bound.
    $largeDefinitions = [];
    $serviceArgument = ['type' => 'service', 'id' => 'shared.' . str_repeat('x', 58)];
    for ($index = 0; $index < 6000; $index++) {
        $largeDefinitions['large.service.' . $index] = [
            'class' => 'App\\LargeService' . $index,
            'arguments' => array_fill(0, 48, $serviceArgument),
        ];
    }
    file_put_contents($types, json_encode(['definitions' => $largeDefinitions, 'aliases' => []], JSON_THROW_ON_ERROR));
    file_put_contents($services, json_encode(['definitions' => [], 'aliases' => []], JSON_THROW_ON_ERROR));
    unset($largeDefinitions);
    [$exit, $largeOutput, $error] = $run();
    if ($exit !== 0 || $error !== '' || strlen($largeOutput) <= 16_777_216) {
        throw new RuntimeException('Large sanitized container export failed: ' . $error);
    }
    file_put_contents($reference, $largeOutput);
    $largeMap = (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
    if (count($largeMap->services) !== 6000 || count($largeMap->services['large.service.0']['arguments']) !== 48) {
        throw new RuntimeException('Large exported container lost service definitions or constructor edges.');
    }
    unset($largeOutput, $largeMap);

    // Large compiled containers exceed the old 16 MiB cap. Valid JSON whitespace
    // exercises the byte limit without constructing an unrelated service graph.
    $largeReference = $output . str_repeat(' ', 17 * 1024 * 1024);
    file_put_contents($reference, $largeReference);
    $largeMap = (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
    if (($largeMap->classBindings()['App\\Contract\\Reader'] ?? null) !== 'App\\Service\\DefaultReader') {
        throw new RuntimeException('A valid reference above 16 MiB lost its service bindings.');
    }
    unset($largeReference, $largeMap);
    $file = fopen($reference, 'w');
    if ($file === false || !ftruncate($file, ContainerReferenceLoader::MAX_REFERENCE_BYTES + 1)) {
        throw new RuntimeException('Could not create the oversized reference fixture.');
    }
    fclose($file);
    clearstatcache(true, $reference);
    try {
        (new ContainerReferenceLoader($root, 'config/container-reference.dev.json'))->load();
        throw new RuntimeException('A reference above 64 MiB was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Container reference exceeds 64 MiB.') throw $error;
    }

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
    if (is_file($root . '/vendor/autoload.php')) { unlink($root . '/vendor/autoload.php'); rmdir($root . '/vendor'); }
    rmdir($root . '/config');
    rmdir($root);
}
echo "Compiled container reference checks passed\n";
