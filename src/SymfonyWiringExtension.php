<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use ByteKitsune\MagoSymfonyWiring\Analyzer\WiringPlugin;
use Mago\Sdk\Extension;

final class SymfonyWiringExtension
{
    public const VERSION = '0.1.0-beta.4';
    /** @param list<string> $serviceFiles Explicit, ordered checkout-relative shared/dev files. */
    public static function create(string $projectRoot, array $serviceFiles): Extension
    {
        return new Extension(
            identifier: 'byte-kitsune/symfony-wiring',
            name: 'Symfony service wiring',
            version: self::VERSION,
            analyzerPlugins: [new WiringPlugin(new ServiceConfigLoader($projectRoot, $serviceFiles))],
        );
    }
}
