<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Analyzer;

use ByteKitsune\MagoSymfonyWiring\ServiceMapLoader;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class WiringPlugin implements Plugin
{
    public function __construct(private readonly ServiceMapLoader $loader) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/symfony-wiring', 'Symfony wiring', 'Checks literal dev service wiring and Target aliases.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterFileAnalysisHook(new AutowiringReferenceHook($this->loader));
        $registry->registerAfterAnalysisHook(new TargetWiringHook($this->loader));
    }
}
