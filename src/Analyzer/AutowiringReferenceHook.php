<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Analyzer;

use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;
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
    public function __construct(private readonly ServiceConfigLoader $loader) {}

    public function getRequirements(): array { return []; }

    public function afterFileAnalysis(AfterFileAnalysisContext $context): void
    {
        if (!str_ends_with($context->analysis->file, '.php')) return;
        $map = $this->loader->load();
        if ($map->incomplete !== []) return;
        $source = $context->analysis->getSourceFile();
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source->contents);
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
            foreach ($constructor->params as $parameter) {
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
                $id = $target === null ? $map->resolveType($type) : $map->resolveTarget($type, $target);
                $class = $id === null ? null : $map->services[$id]['class'];
                if ($class === null || $context->codebase->getClassLike($class) === null) continue;
                $context->references->add(new MemberIdentifier($owner, '__construct'), $class);
            }
        }
    }
}
