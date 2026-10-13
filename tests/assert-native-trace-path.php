<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/NativeEngine.php';
use ByteKitsune\MagoNative\Engine;
putenv('MAGO_NATIVE_TRACE=1');
foreach (['relative-trace.log', sys_get_temp_dir() . '/mago-missing-' . bin2hex(random_bytes(8)) . '/trace.log'] as $path) {
    putenv('MAGO_NATIVE_TRACE_FILE=' . $path);
    try { Engine::stats(); throw new LogicException('Invalid native trace file was accepted.'); } catch (RuntimeException $error) {
        if (!str_starts_with($error->getMessage(), 'MAGO_NATIVE_TRACE_FILE')) throw $error;
    }
}
putenv('MAGO_NATIVE_TRACE_FILE');
putenv('MAGO_NATIVE_TRACE');
Engine::stats();
echo "Invalid trace paths fail explicitly without corrupting the engine session\n";
