<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class PhpSecretInspector
{
    public function __construct(private readonly SecretPolicy $policy) {}

    /** @return list<array{start:int,end:int}> */
    public function inspect(string $source): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $findings = [];
        foreach ((new NodeFinder())->find($nodes, fn(Node $n) => true) as $node) {
            $key = null;
            $value = null;
            if ($node instanceof Node\Expr\Assign) {
                $key = $this->name($node->var);
                $value = $node->expr;
            } elseif ($node instanceof Node\ArrayItem && $node->key instanceof Node\Scalar\String_) {
                $key = $node->key->value;
                $value = $node->value;
            } elseif ($node instanceof Node\Const_ || $node instanceof Node\PropertyItem) {
                $key = $node->name->toString();
                $value = $node instanceof Node\Const_ ? $node->value : $node->default;
            } elseif ($node instanceof Node\Param) {
                $key = $this->name($node->var);
                $value = $node->default;
            } elseif ($node instanceof Node\Arg && $node->name !== null) {
                $key = $node->name->toString();
                $value = $node->value;
            }
            if ($key === null || !$this->policy->sensitive($key) || !$value instanceof Node) continue;
            $literal = $this->literal($value);
            if (!$this->policy->hardcoded($literal)) continue;
            $start = $value->getStartFilePos();
            $end = $value->getEndFilePos() + 1;
            $findings[$start . ':' . $end] = ['start' => $start, 'end' => $end];
        }
        return array_values($findings);
    }

    private function name(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) return $node->name;
        if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\StaticPropertyFetch) && $node->name instanceof Node\Identifier) return $node->name->toString();
        if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim instanceof Node\Scalar\String_) return $node->dim->value;
        return null;
    }

    private function literal(Node $node): string|int|float|null
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) return $node->value;
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->literal($node->left);
            $right = $this->literal($node->right);
            if ($left !== null && $right !== null) return (string) $left . (string) $right;
        }
        if (($node instanceof Node\Expr\UnaryMinus || $node instanceof Node\Expr\UnaryPlus) && is_numeric($value = $this->literal($node->expr))) return $node instanceof Node\Expr\UnaryMinus ? -$value : +$value;
        return null;
    }
}
