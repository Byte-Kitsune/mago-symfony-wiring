<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/NativeRuntime.php';

use ByteKitsune\MagoNative\Runtime;

$resolve = new ReflectionMethod(Runtime::class, 'platformIdentifier');
foreach ([
    ['Linux', 'aarch64', 'linux-arm64'],
    ['Linux', 'arm64', 'linux-arm64'],
    ['Linux', 'ARM64', 'linux-arm64'],
    ['Linux', 'amd64', 'linux-x86_64'],
    ['Linux', 'x86_64', 'linux-x86_64'],
    ['Darwin', 'aarch64', 'macos-arm64'],
    ['Darwin', 'arm64', 'macos-arm64'],
    ['Darwin', 'x86_64', 'macos-x86_64'],
] as [$os, $arch, $expected]) {
    if ($resolve->invoke(null, $os, $arch) !== $expected) {
        throw new RuntimeException('Wrong native release platform for ' . $os . '/' . $arch);
    }
}
foreach ([['Windows', 'arm64'], ['Linux', 'armv7l'], ['Darwin', 'unknown']] as [$os, $arch]) {
    try {
        $resolve->invoke(null, $os, $arch);
        throw new LogicException('Unsupported native platform was accepted.');
    } catch (RuntimeException) {
    }
}
if (Runtime::platform() !== $resolve->invoke(null, PHP_OS_FAMILY, php_uname('m'))) {
    throw new RuntimeException('Native platform detection did not use the current host.');
}
echo "Linux aarch64/arm64, x86_64 and macOS platform selection passed\n";
