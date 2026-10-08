<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

final class ConfigSecretInspector
{
    /**
     * Inspect explicit repository-relative PHP/YAML files without executing configuration.
     * @param list<string> $files
     * @param array{sensitiveKeys?:list<string>,excludePaths?:list<string>} $options
     * @return array{schema_version:int,column_encoding:string,issues:list<array>,incomplete:list<array{path:string,reason:string}>}
     */
    public static function inspectConfigFiles(string $projectRoot, array $files, array $options = []): array
    {
        $policy = new SecretPolicy($options);
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) throw new \InvalidArgumentException('Project root must be an existing directory.');
        if (count($files) > 512) throw new \InvalidArgumentException('At most 512 explicit configuration files may be inspected.');
        $totalBytes = 0;
        $result = ['schema_version' => 1, 'column_encoding' => 'utf8_bytes', 'issues' => [], 'incomplete' => []];
        foreach (array_unique($files) as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 1024 || str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) throw new \InvalidArgumentException('Configuration paths must be repository-relative.');
            if ($policy->excluded($path)) continue;
            $full = realpath($root . '/' . $path);
            if ($full === false || !str_starts_with($full, $root . '/') || !is_file($full)) { $result['incomplete'][] = ['path' => $path, 'reason' => 'File is missing or outside the project root.']; continue; }
            if (filesize($full) > 1048576) { $result['incomplete'][] = ['path' => $path, 'reason' => 'File exceeds the 1 MiB inspection limit.']; continue; }
            $totalBytes += filesize($full);
            if ($totalBytes > 16777216 || count($result['issues']) >= 4096) {
                $result['incomplete'][] = ['path' => $path, 'reason' => 'Batch exceeded the 16 MiB or 4096 issue inspection limit.'];
                continue;
            }
            $source = file_get_contents($full);
            if ($source === false) { $result['incomplete'][] = ['path' => $path, 'reason' => 'File cannot be read.']; continue; }
            try {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($extension === 'php') $spans = (new PhpSecretInspector($policy))->inspect($source);
                elseif (in_array($extension, ['yaml', 'yml'], true)) {
                    $yaml = (new YamlSecretInspector($policy))->inspect($source);
                    $spans = $yaml['spans'];
                    foreach ($yaml['incomplete'] as $reason) $result['incomplete'][] = ['path' => $path, 'reason' => $reason];
                } else { $result['incomplete'][] = ['path' => $path, 'reason' => 'Only PHP and YAML files are supported.']; continue; }
                foreach ($spans as $span) {
                    if (count($result['issues']) >= 4096) {
                        $result['incomplete'][] = ['path' => $path, 'reason' => 'Batch exceeded the 4096 issue inspection limit.'];
                        break;
                    }
                    $result['issues'][] = SecretPolicy::issue($path, $source, $span['start'], $span['end']);
                }
            } catch (\Throwable) {
                // Parser exceptions may contain literal credentials: never serialize their messages.
                $result['incomplete'][] = ['path' => $path, 'reason' => 'Configuration syntax could not be parsed.'];
            }
        }
        return $result;
    }
}
