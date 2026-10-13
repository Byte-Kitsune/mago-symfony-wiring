<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/NativeRuntime.php';

use ByteKitsune\MagoNative\Runtime;

$resolve = new ReflectionMethod(Runtime::class, 'platformIdentifier');
foreach ([
    ['Linux', 'aarch64', 'gnu', 'linux-arm64'],
    ['Linux', 'arm64', 'gnu', 'linux-arm64'],
    ['Linux', 'ARM64', 'musl', 'linux-arm64-musl'],
    ['Linux', 'aarch64', 'musl', 'linux-arm64-musl'],
    ['Linux', 'amd64', 'gnu', 'linux-x86_64'],
    ['Linux', 'x86_64', 'gnu', 'linux-x86_64'],
    ['Linux', 'amd64', 'musl', 'linux-x86_64-musl'],
    ['Linux', 'x86_64', 'musl', 'linux-x86_64-musl'],
    ['Darwin', 'aarch64', 'gnu', 'macos-arm64'],
    ['Darwin', 'arm64', 'gnu', 'macos-arm64'],
    ['Darwin', 'x86_64', 'gnu', 'macos-x86_64'],
] as [$os, $arch, $libc, $expected]) {
    if ($resolve->invoke(null, $os, $arch, $libc) !== $expected) {
        throw new RuntimeException('Wrong native release platform for ' . $os . '/' . $arch . '/' . $libc);
    }
}
foreach ([['Windows', 'arm64', 'gnu'], ['Linux', 'armv7l', 'gnu'], ['Darwin', 'unknown', 'gnu'], ['Linux', 'x86_64', 'unknown']] as [$os, $arch, $libc]) {
    try {
        $resolve->invoke(null, $os, $arch, $libc);
        throw new LogicException('Unsupported native platform was accepted.');
    } catch (RuntimeException) {
    }
}
$detect = new ReflectionMethod(Runtime::class, 'libcFromMaps');
foreach ([
    ["/lib/ld-musl-x86_64.so.1\n", 'musl'],
    ["/lib/ld-musl-aarch64.so.1\n", 'musl'],
    ["/usr/lib/libc.musl-aarch64.so.1\n", 'musl'],
    ["/usr/lib/x86_64-linux-gnu/libc.so.6\n", 'gnu'],
    ["/lib/aarch64-linux-gnu/libc-2.31.so\n", 'gnu'],
    ["/lib64/ld-linux-x86-64.so.2\n", 'gnu'],
    ["/lib/ld-linux-aarch64.so.1\n", 'gnu'],
    ["unavailable procfs\n", 'musl'],
    ["", 'musl'],
] as [$maps, $expected]) {
    if ($detect->invoke(null, $maps) !== $expected) throw new RuntimeException('Wrong loaded-libc detection.');
}
$libc = PHP_OS_FAMILY === 'Linux' ? (new ReflectionMethod(Runtime::class, 'linuxLibc'))->invoke(null) : 'gnu';
if (Runtime::platform() !== $resolve->invoke(null, PHP_OS_FAMILY, php_uname('m'), $libc)) {
    throw new RuntimeException('Native platform detection did not use the current host.');
}
if (getenv('MAGO_EXPECT_MUSL') === '1' && !str_ends_with(Runtime::platform(), '-musl')) {
    throw new RuntimeException('Alpine did not select the musl release/cache.');
}
echo "GNU/musl platform selection, libc detection and isolated cache identifiers passed\n";
