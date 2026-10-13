<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/NativeRuntime.php';
use ByteKitsune\MagoNative\Runtime;

final class ReleaseFixtureStream
{
    public mixed $context;
    public static string $binary = "#!/bin/sh\nexit 0\n";
    public static bool $badChecksum = true;
    public static int $requests = 0;
    private string $bytes = '';
    private int $offset = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        ++self::$requests;
        $asset = 'mago-extension-engine-' . Runtime::platform();
        $base = 'https://github.com/Byte-Kitsune/mago-architecture-graph/releases/download/v1.3.0-RC3/';
        if ($path === $base . $asset . '.sha256') $this->bytes = (self::$badChecksum ? str_repeat('0', 64) : hash('sha256', self::$binary)) . '  ' . $asset . "\n";
        elseif ($path === $base . $asset) $this->bytes = self::$binary;
        else return false;
        return true;
    }
    public function stream_read(int $count): string { $value = substr($this->bytes, $this->offset, $count); $this->offset += strlen($value); return $value; }
    public function stream_eof(): bool { return $this->offset >= strlen($this->bytes); }
    public function stream_stat(): array { return []; }
}
$cache = sys_get_temp_dir() . '/mago-install-' . bin2hex(random_bytes(8));
$oldCache = getenv('XDG_CACHE_HOME'); $oldDownload = getenv('MAGO_NATIVE_DOWNLOAD');
putenv('XDG_CACHE_HOME=' . $cache); putenv('MAGO_NATIVE_DOWNLOAD=1');
stream_wrapper_unregister('https'); stream_wrapper_register('https', ReleaseFixtureStream::class);
try {
    try { Runtime::install(); throw new LogicException('Bad checksum was accepted.'); } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'checksum mismatch')) throw $error;
    }
    $directory = $cache . '/mago-extension-engine/' . Runtime::VERSION . '/' . Runtime::platform();
    if (is_file($directory . '/mago-extension-engine')) throw new RuntimeException('Bad download produced a cached executable.');
    ReleaseFixtureStream::$badChecksum = false;
    $path = Runtime::install();
    if (!is_executable($path) || file_get_contents($path) !== ReleaseFixtureStream::$binary) throw new RuntimeException('Verified installation failed.');
    $requests = ReleaseFixtureStream::$requests;
    if (Runtime::install() !== $path || ReleaseFixtureStream::$requests !== $requests) throw new RuntimeException('Shared cache performed another download.');
    if (glob($directory . '/engine-*') !== []) throw new RuntimeException('Installer left temporary binaries behind.');
} finally {
    stream_wrapper_restore('https');
    $directory = $cache . '/mago-extension-engine/' . Runtime::VERSION . '/' . Runtime::platform();
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) { rmdir($directory); rmdir(dirname($directory)); rmdir(dirname($directory, 2)); rmdir($cache); }
    putenv($oldCache === false ? 'XDG_CACHE_HOME' : 'XDG_CACHE_HOME=' . $oldCache);
    putenv($oldDownload === false ? 'MAGO_NATIVE_DOWNLOAD' : 'MAGO_NATIVE_DOWNLOAD=' . $oldDownload);
}
echo "Checksum rejection, atomic installation and shared-cache reuse passed\n";
