<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use ByteKitsune\MagoSymfonyWiring\Analyzer\WiringPlugin;
use Mago\Sdk\Extension;

final class SymfonyWiringExtension
{
    public const VERSION = '0.1.0-beta.10';
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
