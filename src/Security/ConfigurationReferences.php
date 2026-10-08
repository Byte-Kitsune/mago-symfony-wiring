<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

use PhpParser\Node;

/** Proves a narrow, immutable top-level require -> Worker extension-list data flow. */
final class ConfigurationReferences
{
    /** @return list<string> */
    public static function inspect(array $nodes, string $sourcePath): array
    {
        $paths = [];
        $lists = [];
        foreach ($nodes as $statement) {
            if ($statement instanceof Node\Stmt\Use_ || $statement instanceof Node\Stmt\Declare_ || $statement instanceof Node\Stmt\Nop) continue;
            if ($statement instanceof Node\Stmt\If_ && $statement->else === null && $statement->elseifs === [] && count($statement->stmts) === 1 && self::safeCondition($statement->cond, $paths, $sourcePath) && self::wiringAppend($statement->stmts[0], $lists)) continue;
            if (!$statement instanceof Node\Stmt\Expression) return [];
            $expr = $statement->expr;
            if ($expr instanceof Node\Expr\Include_ && str_contains(self::path($expr->expr, $paths, $sourcePath) ?? '', 'autoload.php')) continue;
            if ($expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                if (isset($paths[$name]) || isset($lists[$name])) return []; // No reassignment/alias snapshots.
                if ($expr->expr instanceof Node\Expr\Include_) {
                    $path = self::path($expr->expr->expr, $paths, $sourcePath);
                    if ($path === null || !str_starts_with($path, '/') || str_contains($path, "\0")) return [];
                    $lists[$name] = $path;
                } else {
                    $path = self::path($expr->expr, $paths, $sourcePath);
                    if ($path === null) return [];
                    $paths[$name] = $path;
                }
                continue;
            }
            if (self::wiringAppend($statement, $lists)) continue;
            if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'run' && $expr->var instanceof Node\Expr\New_ && $expr->var->class instanceof Node\Name && strcasecmp($expr->var->class->toString(), 'Mago\\Sdk\\Worker') === 0) {
                $references = [];
                foreach ($expr->var->args as $arg) {
                    if (!$arg->unpack || !$arg->value instanceof Node\Expr\Variable || !is_string($arg->value->name) || !isset($lists[$arg->value->name])) return [];
                    $references[] = $lists[$arg->value->name];
                }
                return array_values(array_unique($references));
            }
            return [];
        }
        return [];
    }

    private static function safeCondition(Node $condition, array $paths, string $sourcePath): bool
    {
        return $condition instanceof Node\Expr\FuncCall && $condition->name instanceof Node\Name && strcasecmp($condition->name->toString(), 'is_file') === 0 && count($condition->args) === 1 && !$condition->args[0]->unpack && self::path($condition->args[0]->value, $paths, $sourcePath) !== null;
    }

    private static function wiringAppend(Node $statement, array $lists): bool
    {
        if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\Assign) return false;
        $assignment = $statement->expr;
        $target = $assignment->var;
        $call = $assignment->expr;
        foreach ((new \PhpParser\NodeFinder())->findInstanceOf($call, Node\Expr\Variable::class) as $variable) {
            if (is_string($variable->name) && isset($lists[$variable->name])) return false;
        }
        return $target instanceof Node\Expr\ArrayDimFetch && $target->dim === null && $target->var instanceof Node\Expr\Variable && is_string($target->var->name) && isset($lists[$target->var->name])
            && $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && strcasecmp($call->class->toString(), 'ByteKitsune\\MagoSymfonyWiring\\SymfonyWiringExtension') === 0 && $call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), ['create', 'fromcontainerreference'], true);
    }

    private static function path(Node $node, array $paths, string $sourcePath, int $depth = 0): ?string
    {
        if ($depth > 16) return null;
        if ($node instanceof Node\Scalar\String_) return $node->value;
        if ($node instanceof Node\Scalar\MagicConst\Dir) return dirname($sourcePath);
        if ($node instanceof Node\Scalar\MagicConst\File) return $sourcePath;
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) return $paths[$node->name] ?? null;
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $a = self::path($node->left, $paths, $sourcePath, $depth + 1);
            $b = self::path($node->right, $paths, $sourcePath, $depth + 1);
            return $a === null || $b === null ? null : $a . $b;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strcasecmp($node->name->toString(), 'dirname') === 0 && count($node->args) === 1 && !$node->args[0]->unpack) {
            $path = self::path($node->args[0]->value, $paths, $sourcePath, $depth + 1);
            return $path === null ? null : dirname($path);
        }
        return null;
    }
}
