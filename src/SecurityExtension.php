<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use ByteKitsune\MagoSymfonyWiring\Security\HardcodedSecretRule;
use ByteKitsune\MagoSymfonyWiring\Security\SecretPolicy;
use Mago\Sdk\Extension;

/** Explicit companion registration; does not require a Symfony wiring/container reference. */
final class SecurityExtension
{
    /** Inspect explicit worker/returned extension configuration without executing it. */
    public static function inspectConfiguration(string $source, ?string $sourcePath = null): array
    {
        return \ByteKitsune\MagoSymfonyWiring\Security\ConfigurationInspection::inspect($source, $sourcePath);
    }

    /** @param array{sensitiveKeys?:list<string>,excludePaths?:list<string>} $options */
    public static function create(string $projectRoot, array $options = []): Extension
    {
        return new Extension(
            identifier: 'byte-kitsune/symfony-configuration-security',
            name: 'Symfony configuration security',
            version: SymfonyWiringExtension::VERSION,
            linterRules: [new HardcodedSecretRule(new SecretPolicy($options), $projectRoot)],
        );
    }
}
