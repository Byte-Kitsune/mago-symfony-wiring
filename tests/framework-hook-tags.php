<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use ByteKitsune\MagoSymfonyWiring\ContainerReferenceLoader;
$dir = sys_get_temp_dir() . '/hook-tag-fixture-' . bin2hex(random_bytes(6)); mkdir($dir);
$debug = ['definitions' => ['handler' => ['class' => 'Neutral\\Handler', 'tags' => ['messenger.message_handler' => [['method' => 'process', 'password' => 'discarded']], 'kernel.event_listener' => [['event' => 'application.ready', 'method' => 'onReady']], 'unrelated.secret.tag' => [['token' => 'discarded']]], 'arguments' => []]], 'aliases' => []];
file_put_contents($dir . '/types.json', json_encode($debug, JSON_THROW_ON_ERROR));
file_put_contents($dir . '/services.json', json_encode($debug, JSON_THROW_ON_ERROR));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/create-container-reference.php') . ' --types=' . escapeshellarg($dir . '/types.json') . ' --services=' . escapeshellarg($dir . '/services.json');
exec($command, $lines, $status);
if ($status !== 0) throw new RuntimeException('Hook metadata export failed.');
$json = implode("\n", $lines); $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if ($data['schema_version'] !== '3' || str_contains($json, 'discarded') || str_contains($json, 'password') || str_contains($json, 'unrelated.secret.tag')) throw new RuntimeException('Hook exporter leaked arbitrary tag attributes.');
file_put_contents($dir . '/reference.json', $json);
$loader = new ContainerReferenceLoader($dir, 'reference.json');
if ($loader->frameworkHookEntrypoints() !== ['Neutral\\Handler::onReady', 'Neutral\\Handler::process']) throw new RuntimeException('Compiled hook methods were not preserved: ' . json_encode($loader->frameworkHookEntrypoints()));
foreach ($loader->frameworkHookRegistrations()['registrations'] as $record) if ($record['activation'] !== 'registration-only') throw new RuntimeException('Registration was represented as invocation.');
$data['definitions']['handler']['tags']['kernel.event_listener'][0]['secret'] = 'reject';
file_put_contents($dir . '/bad.json', json_encode($data, JSON_THROW_ON_ERROR));
try { (new ContainerReferenceLoader($dir, 'bad.json'))->load(); throw new LogicException('Unsanitized metadata accepted.'); } catch (UnexpectedValueException) {}
foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir);
echo "Compiled hook tags, method targets, metadata sanitization and activation boundary passed\n";
