<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

/** Detection policy; this is a key-based check, not a proof that a project contains no secrets. */
final class SecretPolicy
{
    public const CODE = 'byte-kitsune/symfony-wiring/no-hardcoded-secret';
    private const DEFAULT_KEYS = ['password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'access_token', 'client_secret', 'private_key', 'authorization'];
    /** @var list<string> */
    private readonly array $keys;
    /** @var list<string> */
    private readonly array $excludes;

    /** @param array{sensitiveKeys?: list<string>, excludePaths?: list<string>} $options */
    public function __construct(array $options = [])
    {
        foreach ($options as $key => $_) {
            if (!in_array($key, ['sensitiveKeys', 'excludePaths'], true)) throw new \InvalidArgumentException('Unknown secret policy option: ' . $key);
        }
        $keys = $options['sensitiveKeys'] ?? self::DEFAULT_KEYS;
        $excludes = $options['excludePaths'] ?? [];
        if (!is_array($keys) || count($keys) > 64 || !is_array($excludes) || count($excludes) > 64) throw new \InvalidArgumentException('Secret policy lists must contain at most 64 entries.');
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D', $key) !== 1) throw new \InvalidArgumentException('Invalid sensitive key.');
        }
        foreach ($excludes as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 1024 || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) throw new \InvalidArgumentException('Exclusions must be repository-relative paths or globs.');
        }
        $this->keys = array_values(array_unique(array_map(self::normalize(...), $keys)));
        $this->excludes = $excludes;
    }

    private static function normalize(string $key): string
    {
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', ltrim($key, '$')) ?? $key);
    }

    public function sensitive(string $key): bool
    {
        $key = self::normalize($key);
        foreach ($this->keys as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_' . $sensitive)) return true;
        }
        return false;
    }

    public function excluded(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        foreach ($this->excludes as $path) {
            if ($relativePath === rtrim($path, '/') || str_starts_with($relativePath, rtrim($path, '/') . '/') || fnmatch($path, $relativePath)) return true;
        }
        return false;
    }

    public function hardcoded(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) return true;
        if (!is_string($value) || trim($value) === '') return false;
        // Entire placeholders only. Symfony processors may have numeric arguments (e.g. key:0:DATA).
        if (!str_starts_with($value, '%env(') || !str_ends_with($value, ')%')) return true;
        $parts = explode(':', substr($value, 5, -2));
        $variable = array_pop($parts);
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) !== 1) return true;
        if ($parts === []) return false;
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $parts[0]) !== 1) return true;
        foreach ($parts as $index => $part) {
            // default::VAR is Symfony's documented null fallback. Processor arguments may
            // name parameters (app.secret), array keys or enum classes (App\Enum\Kind).
            if ($part === '' && $index > 0 && $parts[$index - 1] === 'default') continue;
            if (preg_match('/\A[A-Za-z0-9_.\\\\-]+\z/D', $part) !== 1) return true;
        }
        return false;
    }

    /** @return array{code:string,severity:string,path:string,line:int,column:int,end_line:int,end_column:int,message:string} */
    public static function issue(string $path, string $source, int $start, int $end): array
    {
        $prefix = substr($source, 0, $start);
        $line = substr_count($prefix, "\n") + 1;
        $last = strrpos($prefix, "\n");
        $endPrefix = substr($source, 0, $end);
        $endLast = strrpos($endPrefix, "\n");
        return ['code' => self::CODE, 'severity' => 'error', 'path' => $path,
            'line' => $line, 'column' => $start - ($last === false ? -1 : $last),
            'end_line' => substr_count($endPrefix, "\n") + 1, 'end_column' => $end - ($endLast === false ? -1 : $endLast),
            'message' => 'Hardcoded credential in a sensitive field; use an environment variable or a secret provider.'];
    }
}
