<?php

declare(strict_types=1);

namespace ByteKitsune\MagoNative;

/** Installs publisher release assets; analyzed source never selects an executable. */
final class Runtime
{
    public const VERSION = '0.3.0';
    private const RELEASE = 'https://github.com/Byte-Kitsune/mago-architecture-graph/releases/download/v1.3.0/';

    public static function executable(): string
    {
        $override = getenv('MAGO_NATIVE_ENGINE');
        if (is_string($override) && $override !== '') return self::checkExecutable($override);
        foreach ([dirname(__DIR__) . '/native/bin/mago-extension-engine', dirname(__DIR__) . '/native/target/release/mago-extension-engine'] as $path) {
            if (is_file($path) && is_executable($path)) return $path;
        }
        return self::install();
    }

    public static function platform(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => throw new \RuntimeException('Native engine does not support this operating system yet.'),
        };
        $arch = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new \RuntimeException('Unsupported native engine CPU architecture.'),
        };
        if ($os === 'linux' && $arch !== 'x86_64') throw new \RuntimeException('Linux arm64 binaries are not available yet; set MAGO_NATIVE_ENGINE to a source build.');
        return $os . '-' . $arch;
    }

    public static function install(): string
    {
        $platform = self::platform();
        $cache = getenv('XDG_CACHE_HOME');
        if (!is_string($cache) || $cache === '') {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') throw new \RuntimeException('Set XDG_CACHE_HOME or MAGO_NATIVE_ENGINE for the native engine.');
            $cache = $home . '/.cache';
        }
        $directory = $cache . '/mago-extension-engine/' . self::VERSION . '/' . $platform;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create native engine cache.');
        $path = $directory . '/mago-extension-engine';
        if (is_link($path)) throw new \RuntimeException('Native engine cache executable must not be a symlink.');
        if (is_file($path) && is_executable($path)) return $path;
        if (getenv('MAGO_NATIVE_DOWNLOAD') === '0') throw new \RuntimeException('Native engine is missing; install it first or set MAGO_NATIVE_ENGINE to a source build.');
        $lockPath = $directory . '/install.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Native engine install lock must not be a symlink.');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock native engine installation.');
        $temporary = null;
        try {
            clearstatcache(true, $path);
            if (is_link($path)) throw new \RuntimeException('Native engine cache executable must not be a symlink.');
            if (is_file($path) && is_executable($path)) return $path;
            fwrite(STDERR, 'Installing Mago native engine ' . self::VERSION . ' (' . $platform . ')…' . PHP_EOL);
            $asset = 'mago-extension-engine-' . $platform;
            $checksum = trim(self::download(self::RELEASE . $asset . '.sha256', 1024));
            if (!preg_match('/\A([a-f0-9]{64})  ' . preg_quote($asset, '/') . '\z/D', $checksum, $matches)) throw new \RuntimeException('Invalid native engine checksum asset.');
            $binary = self::download(self::RELEASE . $asset, 32 * 1024 * 1024);
            if (!hash_equals($matches[1], hash('sha256', $binary))) throw new \RuntimeException('Native engine checksum mismatch.');
            $temporary = tempnam($directory, 'engine-');
            if ($temporary === false || file_put_contents($temporary, $binary) !== strlen($binary) || !chmod($temporary, 0700) || !rename($temporary, $path)) throw new \RuntimeException('Cannot install verified native engine.');
            $temporary = null;
            return $path;
        } finally {
            if (is_string($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function checkExecutable(string $path): string
    {
        if (!is_file($path) || !is_executable($path)) throw new \RuntimeException('MAGO_NATIVE_ENGINE must name an executable native engine.');
        return $path;
    }

    private static function download(string $url, int $limit): string
    {
        $context = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: mago-extension-engine-installer\r\n", 'follow_location' => 1, 'max_redirects' => 5]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) throw new \RuntimeException('Cannot download native engine release asset. Set MAGO_NATIVE_ENGINE for offline use.');
        try {
            $bytes = stream_get_contents($stream, $limit + 1);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $limit) throw new \RuntimeException('Invalid or oversized native engine release asset.');
            return $bytes;
        } finally { fclose($stream); }
    }
}
