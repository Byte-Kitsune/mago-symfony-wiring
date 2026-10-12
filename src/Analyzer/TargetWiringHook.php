<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Analyzer;

if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once dirname(__DIR__) . '/NativeParser.php';


use ByteKitsune\MagoSymfonyWiring\ServiceMapLoader;
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
    public function __construct(private readonly ServiceMapLoader $loader) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
        $map = $this->loader->load();
        $constructorBindings = $this->loader->constructorClassBindings();
        $parser = \ByteKitsune\MagoNative\Parser::create();
        $finder = new NodeFinder();
        $sourceFiles = 0;
        $firstSource = null;
        foreach ($context->analysis->files as $file) {
            $context->cancellation->throwIfCancelled();
            if (!str_ends_with($file->file, '.php')) continue;
            $source = $file->getSourceFile();
            $firstSource ??= $source;
            $sourceFiles++;
            if (\ByteKitsune\MagoNative\Engine::enabled()) {
                try {
                    $constructors = \ByteKitsune\MagoNative\Engine::analyze($source->contents)['constructors'];
                } catch (\PhpParser\Error) {
                    continue; // Native Mago parse diagnostics are authoritative.
                }
                foreach ($constructors as $constructor) foreach ($constructor['parameters'] as $parameter) {
                    $type = $parameter['class_type'];
                    if ($type === null) continue;
                    $owner = $constructor['owner'];
                    $position = $parameter['position'];
                    foreach ($parameter['attributes'] as $attribute) {
                        if ($attribute['name'] !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Target' || $attribute['literal'] === null) continue;
                        $name = $attribute['literal'];
                        if (array_key_exists($position, $constructorBindings[$owner] ?? [])) {
                            if ($constructorBindings[$owner][$position] !== null) continue;
                        } elseif ($map->resolveTarget($type, $name) !== null) continue;
                        $issue = Issue::at('No proven dev service binding for #[Target] ' . $type . ' $' . $name,
                            new SourceLocation($source->path, new Span($attribute['start'], $attribute['end'])));
                        if ($map->incomplete !== []) $issue = $issue->withNote('Service configuration is incomplete: ' . implode('; ', array_slice($map->incomplete, 0, 3)));
                        $context->report(Level::Warning, 'unresolved-target', $issue);
                    }
                }
                continue;
            }
            try {
                $statements = $parser->parse($source->contents);
                if ($statements === null) continue;
                $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
            } catch (\Throwable) {
                continue; // Native Mago parse diagnostics are authoritative.
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $declaration) {
                if (!$declaration->namespacedName instanceof Node\Name) continue;
                $owner = $declaration->namespacedName->toString();
                $constructor = $declaration->getMethod('__construct');
                if ($constructor === null) continue;
                foreach ($constructor->params as $position => $parameter) {
                    if (!$parameter->type instanceof Node\Name) continue;
                    $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
                    foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                        $attributeType = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
                        if ($attributeType !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') continue;
                        $name = $attribute->args[0]->value ?? null;
                        if (!$name instanceof Node\Scalar\String_) continue;
                        if (array_key_exists($position, $constructorBindings[$owner] ?? [])) {
                            if ($constructorBindings[$owner][$position] !== null) continue;
                        } elseif ($map->resolveTarget($type, $name->value) !== null) continue;
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
        if ($firstSource !== null && getenv('MAGO_SYMFONY_WIRING_ATTESTATION') === '1') {
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
