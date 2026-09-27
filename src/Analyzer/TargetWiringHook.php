<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Analyzer;

use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;
use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class TargetWiringHook implements AfterAnalysisHook
{
    public function __construct(private readonly ServiceConfigLoader $loader) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $map = $this->loader->load();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $sourceFiles = 0;
        $firstSource = null;
        foreach ($context->analysis->files as $file) {
            $context->cancellation->throwIfCancelled();
            if (!str_ends_with($file->file, '.php')) continue;
            $source = $file->getSourceFile();
            $firstSource ??= $source;
            $sourceFiles++;
            try {
                $statements = $parser->parse($source->contents);
                if ($statements === null) continue;
                $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
            } catch (\Throwable) {
                continue; // Native Mago parse diagnostics are authoritative.
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\ClassMethod::class) as $method) {
                if (strtolower($method->name->toString()) !== '__construct') continue;
                foreach ($method->params as $parameter) {
                    if (!$parameter->type instanceof Node\Name) continue;
                    $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
                    foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                        $attributeType = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
                        if ($attributeType !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') continue;
                        $name = $attribute->args[0]->value ?? null;
                        if (!$name instanceof Node\Scalar\String_) continue;
                        if ($map->resolveTarget($type, $name->value) !== null) continue;
                        $issue = Issue::at(
                            'No proven dev service binding for #[Target] ' . $type . ' $' . $name->value,
                            new SourceLocation($source->path, new Span($attribute->getStartFilePos(), $attribute->getEndFilePos() + 1)),
                        );
                        if ($map->incomplete !== []) {
                            $issue = $issue->withNote('Service configuration is incomplete: ' . implode('; ', array_slice($map->incomplete, 0, 3)));
                        }
                        $context->report(Level::Warning, 'unresolved-target', $issue);
                    }
                }
            }
        }
        if ($firstSource !== null) {
            $attestation = [
                'schema_version' => '1',
                'extension' => 'byte-kitsune/symfony-wiring',
                'version' => SymfonyWiringExtension::VERSION,
                'capability' => 'service_wiring',
                'complete' => $map->incomplete === [],
                'source_files' => $sourceFiles,
            ];
            $note = 'extension-attestation: ' . json_encode($attestation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $end = $firstSource->contents === '' ? 0 : 1;
            $context->report(Level::Note, 'analysis-attestation', Issue::at('Symfony service wiring analysis completed.', new SourceLocation($firstSource->path, new Span(0, $end)))->withNote($note));
        }
    }
}
