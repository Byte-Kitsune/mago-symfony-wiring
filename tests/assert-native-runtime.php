<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/NativeEngine.php';
require_once dirname(__DIR__) . '/src/NativeParser.php';
require_once dirname(__DIR__) . '/src/NativeRuntime.php';

use ByteKitsune\MagoNative\Engine;
use ByteKitsune\MagoNative\Parser;
use ByteKitsune\MagoNative\Runtime;

putenv('MAGO_EXTENSION_BACKEND');
if (!Engine::enabled()) throw new RuntimeException('Rust must be the default backend.');
putenv('MAGO_EXTENSION_BACKEND=php');
if (Engine::enabled()) throw new RuntimeException('Explicit PHP reference backend was ignored.');
putenv('MAGO_EXTENSION_BACKEND=invalid');
try { Engine::enabled(); throw new LogicException('Invalid backend accepted.'); } catch (RuntimeException) {}
putenv('MAGO_EXTENSION_BACKEND');
$binary = getenv('MAGO_NATIVE_ENGINE');
if (!is_string($binary) || $binary === '') throw new RuntimeException('The runtime test requires its built native engine.');
if (Runtime::executable() !== $binary) throw new RuntimeException('Explicit engine path was ignored.');
Engine::stats();
$parser = Parser::create();
try { $parser->parse('<?php echo 1;'); throw new LogicException('Unsupported grammar must not select PHP.'); } catch (PhpParser\Error) {}
if (Parser::$fallbackParses !== 0) throw new RuntimeException('Native syntax fell back to PHP.');
$parser->parse('<?php final class RuntimeProbe {}');
if (Parser::$nativeParses !== 1) throw new RuntimeException('Syntax error poisoned the native session.');

// A shared offline cache must reject symlinked executables before running them.
$cache = sys_get_temp_dir() . '/mago-runtime-' . bin2hex(random_bytes(8));
$oldCache = getenv('XDG_CACHE_HOME');
$oldDownload = getenv('MAGO_NATIVE_DOWNLOAD');
putenv('XDG_CACHE_HOME=' . $cache);
putenv('MAGO_NATIVE_DOWNLOAD=0');
try {
    try { Runtime::install(); throw new LogicException('Missing offline engine was accepted.'); } catch (RuntimeException) {}
    $directory = $cache . '/mago-extension-engine/' . Runtime::VERSION . '/' . Runtime::platform();
    symlink($binary, $directory . '/mago-extension-engine');
    try { Runtime::install(); throw new LogicException('Symlinked cached engine was accepted.'); } catch (RuntimeException) {}
    unlink($directory . '/mago-extension-engine');
    rmdir($directory);
    rmdir(dirname($directory));
    rmdir(dirname($directory, 2));
    rmdir($cache);
} finally {
    putenv($oldCache === false ? 'XDG_CACHE_HOME' : 'XDG_CACHE_HOME=' . $oldCache);
    putenv($oldDownload === false ? 'MAGO_NATIVE_DOWNLOAD' : 'MAGO_NATIVE_DOWNLOAD=' . $oldDownload);
}
echo "Native default, strict parsing and offline cache checks passed\n";
