<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use ByteKitsune\MagoSymfonyWiring\Analyzer\WiringPlugin;
use Mago\Sdk\Extension;

final class SymfonyWiringExtension
{
    public const VERSION = '1.3.0-RC1';
    /** Name-resolved hook candidates from the exact snapshot; declarations do not prove activation. */
    public static function inspectFrameworkHooks(\Mago\Sdk\Analyzer\ProjectAnalysis $analysis): array
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once __DIR__ . '/NativeEngine.php';
        if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once __DIR__ . '/NativeParser.php';
        if (!class_exists(\ByteKitsune\MagoNative\FrameworkHooks::class, false)) require_once __DIR__ . '/FrameworkHooks.php';
        $sources = [];
        foreach ($analysis->files as $file) if (str_ends_with($file->file, '.php')) $sources[$file->file] = $file->getSourceFile()->contents;
        return \ByteKitsune\MagoNative\FrameworkHooks::discover($sources);
    }

    /** @param list<string> $serviceFiles Explicit, ordered checkout-relative shared/dev files. */
    public static function create(string $projectRoot, array $serviceFiles): Extension
    {
        return self::withLoader(new ServiceConfigLoader($projectRoot, $serviceFiles));
    }

    public static function fromContainerReference(string $projectRoot, string $referenceFile): Extension
    {
        return self::withLoader(new ContainerReferenceLoader($projectRoot, $referenceFile));
    }

    private static function withLoader(ServiceMapLoader $loader): Extension
    {
        return new Extension(
            identifier: 'byte-kitsune/symfony-wiring',
            name: 'Symfony service wiring',
            version: self::VERSION,
            analyzerPlugins: [new WiringPlugin($loader)],
        );
    }
}
