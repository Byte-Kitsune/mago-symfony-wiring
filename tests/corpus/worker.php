<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$probe = new class implements Plugin {
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('test/wiring-references', 'Wiring reference probe', 'Checks final Mago graph contributions.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new class implements AfterAnalysisHook {
            public function afterAnalysis(AfterAnalysisContext $context): void
            {
                $incoming = $context->analysis->references->getReferencesTo('App\\DevFormatter');
                $proven = false;
                foreach ($incoming as $reference) {
                    $owner = $reference->source->symbol;
                    if ($owner instanceof MemberIdentifier && strcasecmp($owner->class, 'App\\GoodService') === 0 && $owner->member === '__construct') $proven = true;
                    if ($owner instanceof MemberIdentifier && strcasecmp($owner->class, 'App\\BadService') === 0) throw new RuntimeException('An unresolved target added a concrete-class reference.');
                }
                if (!$proven) throw new RuntimeException('Proven named autowiring did not add its concrete-class reference.');
                $default = false;
                foreach ($context->analysis->references->getReferencesTo('App\\Formatter') as $reference) {
                    $owner = $reference->source->symbol;
                    if ($owner instanceof MemberIdentifier && strcasecmp($owner->class, 'App\\GoodService') === 0) throw new RuntimeException('The overridden shared alias was incorrectly referenced.');
                    if ($owner instanceof MemberIdentifier && strcasecmp($owner->class, 'App\\DefaultService') === 0 && $owner->member === '__construct') $default = true;
                }
                if (!$default) throw new RuntimeException('Default autowiring did not add its concrete-class reference.');
            }
        });
    }
};

(new Worker(SymfonyWiringExtension::create(__DIR__, [
    'config/services.yaml',
    'config/services.dev.yaml',
]), new Extension('test/wiring-references', 'Wiring reference probe', '1', analyzerPlugins: [$probe])))->run();
