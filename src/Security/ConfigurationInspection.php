<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Proves only bounded, unconditional declarations; never executes a worker/configuration. */
final class ConfigurationInspection
{
    private const SECURITY = 'ByteKitsune\\MagoSymfonyWiring\\SecurityExtension';

    public static function inspect(string $source, ?string $sourcePath = null): array
    {
        $unresolved = ['schema_version' => 1, 'status' => 'unresolved'];
        if (strlen($source) > 1048576) return $unresolved;
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser(new NameResolver());
            $nodes = $traverser->traverse($nodes);
        } catch (\Throwable) { return $unresolved; }
        $finder = new NodeFinder();
        $calls = $finder->find($nodes, fn(Node $n) => self::securityCall($n));
        if ($calls === []) {
            if ($sourcePath !== null) {
                $references = ConfigurationReferences::inspect($nodes, $sourcePath);
                if ($references !== []) return [...$unresolved, 'references' => $references];
            }
            $includes = $finder->findInstanceOf($nodes, Node\Expr\Include_::class);
            foreach ($includes as $include) {
                $text = substr($source, $include->getStartFilePos(), $include->getEndFilePos() - $include->getStartFilePos() + 1);
                if (!str_contains($text, 'autoload.php')) return $unresolved;
            }
            return ['schema_version' => 1, 'status' => 'absent'];
        }
        $accepted = [];
        $variables = [];
        $workers = [];
        foreach ($nodes as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                // A namespaced configuration needs an explicit supported top-level representation.
                return $unresolved;
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign && $statement->expr->var instanceof Node\Expr\Variable && is_string($statement->expr->var->name)) {
                $value = $statement->expr->expr;
                $name = $statement->expr->var->name;
                $variables[$name] = self::extensionCalls($value, $variables);
                unset($workers[$name]);
                if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name && strcasecmp($value->class->toString(), 'Mago\\Sdk\\Worker') === 0) {
                    $workers[$name] = [];
                    foreach ($value->args as $arg) $workers[$name] = [...$workers[$name], ...self::extensionCalls($arg->value, $variables)];
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null) {
                $accepted = [...$accepted, ...self::extensionCalls($statement->expr, $variables)];
                break;
            }
            if ($statement instanceof Node\Stmt\Expression) {
                $expression = $statement->expr;
                if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'run') {
                    $worker = $expression->var;
                    if ($worker instanceof Node\Expr\Variable && is_string($worker->name) && isset($workers[$worker->name])) {
                        $accepted = [...$accepted, ...$workers[$worker->name]];
                        continue;
                    }
                    if ($worker instanceof Node\Expr\New_ && $worker->class instanceof Node\Name && strcasecmp($worker->class->toString(), 'Mago\\Sdk\\Worker') === 0) {
                        foreach ($worker->args as $arg) $accepted = [...$accepted, ...self::extensionCalls($arg->value, $variables)];
                        continue;
                    }
                }
                if ($expression instanceof Node\Expr\Include_ && str_contains(substr($source, $expression->getStartFilePos(), $expression->getEndFilePos() - $expression->getStartFilePos() + 1), 'autoload.php')) continue;
                return $unresolved;
            }
            if (!$statement instanceof Node\Stmt\Use_ && !$statement instanceof Node\Stmt\Declare_ && !$statement instanceof Node\Stmt\Nop) return $unresolved;
        }
        // No extra unproven/conditional/deferred security registrations are accepted.
        if (count($accepted) !== 1 || count($calls) !== 1) return $unresolved;
        $call = $accepted[0];
        $options = [];
        foreach ($call->args as $index => $arg) {
            if ($arg->unpack) return $unresolved;
            $name = $arg->name?->toString();
            if ($name === 'options' || ($name === null && $index === 1)) {
                try { $options = self::literal($arg->value); } catch (\Throwable) { return $unresolved; }
            } elseif ($name !== null && $name !== 'projectRoot') return $unresolved;
        }
        if (!is_array($options)) return $unresolved;
        try { new SecretPolicy($options); } catch (\Throwable) { return $unresolved; }
        return ['schema_version' => 1, 'status' => 'enabled', 'options' => $options];
    }

    private static function securityCall(Node $node): bool
    {
        return $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && strcasecmp($node->class->toString(), self::SECURITY) === 0 && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'create';
    }

    /** @return list<Node\Expr\StaticCall> */
    private static function extensionCalls(Node $node, array $variables, int $depth = 0): array
    {
        if ($depth > 16) return [];
        if (self::securityCall($node)) return [$node];
        if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($variables[$node->name])) return $variables[$node->name];
        if ($node instanceof Node\Expr\Array_) {
            $calls = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) return [];
                $calls = [...$calls, ...self::extensionCalls($item->value, $variables, $depth + 1)];
            }
            return $calls;
        }
        return [];
    }

    private static function literal(Node $node): mixed
    {
        if ($node instanceof Node\Scalar\String_) return $node->value;
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) throw new \InvalidArgumentException('Dynamic array.');
                $value = self::literal($item->value);
                if ($item->key === null) $result[] = $value;
                elseif ($item->key instanceof Node\Scalar\String_ && !array_key_exists($item->key->value, $result)) $result[$item->key->value] = $value;
                else throw new \InvalidArgumentException('Invalid or repeated option key.');
            }
            return $result;
        }
        throw new \InvalidArgumentException('Dynamic option.');
    }
}
