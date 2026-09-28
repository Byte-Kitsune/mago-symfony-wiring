<?php

declare(strict_types=1);

namespace ParityFixture;

use Symfony\Component\DependencyInjection\Attribute\Target;

interface FormatterInterface {}

final class DefaultFormatter implements FormatterInterface {}

final class DevFormatter implements FormatterInterface {}

final class TargetConsumer
{
    public function __construct(#[Target('text.formatter')] public readonly FormatterInterface $formatter) {}
}

final class DefaultConsumer
{
    public function __construct(public readonly FormatterInterface $formatter) {}
}
