<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/NativeRuntime.php';
fwrite(STDOUT, \ByteKitsune\MagoNative\Runtime::install() . PHP_EOL);
