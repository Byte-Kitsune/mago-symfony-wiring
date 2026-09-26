<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Attribute {
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    final class Target { public function __construct(public string $name) {} }
}

namespace App {
    use Symfony\Component\DependencyInjection\Attribute\Target;

    interface FormatterInterface {}
    final class Formatter implements FormatterInterface {}
    final class DevFormatter implements FormatterInterface {}
    final class GoodService {
        public function __construct(#[Target('textFormatter')] private FormatterInterface $formatter) {}
    }
    final class DefaultService {
        public function __construct(private FormatterInterface $formatter) {}
    }
    final class BadService {
        public function __construct(#[Target('missingFormatter')] private FormatterInterface $formatter) {}
    }
    final class UnusedService {}
}
