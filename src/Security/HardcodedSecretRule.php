<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;

final class HardcodedSecretRule implements Rule
{
    public function __construct(private readonly SecretPolicy $policy, private readonly string $projectRoot) {}

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(SecretPolicy::CODE, 'No hardcoded credentials', 'Finds literal credentials while permitting complete Symfony env placeholders.', Level::Error, true, [NodeKind::Program]);
    }

    public function lint(LintContext $context): void
    {
        $path = $context->file->path;
        $root = rtrim($this->projectRoot, '/') . '/';
        if (str_starts_with($path, $root)) $path = substr($path, strlen($root));
        if ($this->policy->excluded($path)) return;
        foreach ((new PhpSecretInspector($this->policy))->inspect($context->file->contents) as $issue) {
            $context->report(Issue::new('Hardcoded credential in a sensitive field.', new Span($issue['start'], $issue['end']))
                ->withHelp('Use an environment variable or a secret provider; complete %env(PROCESSOR:VARIABLE)% placeholders are permitted.'));
        }
    }
}
