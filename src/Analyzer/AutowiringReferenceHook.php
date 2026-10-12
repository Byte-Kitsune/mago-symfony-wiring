<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Analyzer;

if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once dirname(__DIR__) . '/NativeParser.php';


use ByteKitsune\MagoSymfonyWiring\ServiceMapLoader;
use Mago\Sdk\Analyzer\AfterFileAnalysisContext;
use Mago\Sdk\Analyzer\AfterFileAnalysisHook;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Adds only constructor-to-concrete-class edges proven by literal dev wiring. */
final class AutowiringReferenceHook implements AfterFileAnalysisHook
{
    public function __construct(private readonly ServiceMapLoader $loader) {}

    public function getRequirements(): array { return []; }

    public function afterFileAnalysis(AfterFileAnalysisContext $context): void
    {
        if (!str_ends_with($context->analysis->file, '.php')) return;
        $map = $this->loader->load();
        if ($map->incomplete !== []) return;
        $constructorBindings = $this->loader->constructorClassBindings();
        $source = $context->analysis->getSourceFile();
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
        if (\ByteKitsune\MagoNative\Engine::enabled()) {
            $this->nativeReferences($context, $source->contents, $map, $constructorBindings);
            return;
        }
        try {
            $statements = \ByteKitsune\MagoNative\Parser::create()->parse($source->contents);
            if ($statements === null) return;
            $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
        } catch (\Throwable) {
            return; // Mago owns parse diagnostics; no reference is inferred from bad syntax.
        }

        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $declaration) {
            if (!$declaration->namespacedName instanceof Node\Name) continue;
            $owner = $declaration->namespacedName->toString();
            $constructor = $declaration->getMethod('__construct');
            if ($constructor === null) continue;
            foreach ($constructor->params as $position => $parameter) {
                if (!$parameter->type instanceof Node\Name) continue;
                $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
                $target = null;
                $invalidTarget = false;
                foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                    $attributeType = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
                    if ($attributeType !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') continue;
                    $value = $attribute->args[0]->value ?? null;
                    if ($target !== null || !$value instanceof Node\Scalar\String_) $invalidTarget = true;
                    else $target = $value->value;
                }
                if ($invalidTarget) continue;
                if (array_key_exists($position, $constructorBindings[$owner] ?? [])) {
                    $class = $constructorBindings[$owner][$position];
                } else {
                    $id = $target === null ? $map->resolveType($type) : $map->resolveTarget($type, $target);
                    $class = $id === null ? null : $map->services[$id]['class'];
                }
                if ($class === null || $context->codebase->getClassLike($class) === null) continue;
                $context->references->add(new MemberIdentifier($owner, '__construct'), $class);
            }
        }
    }
    private function nativeReferences(AfterFileAnalysisContext $context, string $source, \ByteKitsune\MagoSymfonyWiring\ServiceMap $map, array $bindings): void
    {
        try {
            $constructors = \ByteKitsune\MagoNative\Engine::analyze($source)['constructors'];
        } catch (\PhpParser\Error) { return; }
        $references = [];
        foreach ($constructors as $constructor) foreach ($constructor['parameters'] as $parameter) {
            $type = $parameter['class_type'];
            if ($type === null) continue;
            $owner = $constructor['owner']; $position = $parameter['position'];
            $target = null; $invalid = false;
            foreach ($parameter['attributes'] as $attribute) {
                if ($attribute['name'] !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') continue;
                if ($target !== null || $attribute['literal'] === null) $invalid = true;
                else $target = $attribute['literal'];
            }
            if ($invalid) continue;
            if (array_key_exists($position, $bindings[$owner] ?? [])) $class = $bindings[$owner][$position];
            else {
                $id = $target === null ? $map->resolveType($type) : $map->resolveTarget($type, $target);
                $class = $id === null ? null : $map->services[$id]['class'];
            }
            if ($class !== null) $references[] = [$owner, $class];
        }
        // Resolve all concrete targets in batches instead of one round trip per parameter.
        $classes = array_values(array_unique(array_column($references, 1)));
        $known = [];
        foreach (array_chunk($classes, 256) as $batch) {
            $context->cancellation->throwIfCancelled();
            foreach ($context->codebase->getMultipleClassLikes($batch) as $position => $metadata) {
                if ($metadata !== null) $known[$batch[$position]] = true;
            }
        }
        foreach ($references as [$owner, $class]) if (isset($known[$class])) $context->references->add(new MemberIdentifier($owner, '__construct'), $class);
    }

}
