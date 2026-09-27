<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Attribute {
    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    final class Target
    {
        public function __construct(public string $name) {}
    }
}

namespace App {
    use Symfony\Component\DependencyInjection\Attribute\Target;

    interface FormatterInterface
    {
        public function format(string $text): string;
    }

    final class SharedFormatter implements FormatterInterface
    {
        public function format(string $text): string { return $text; }
    }

    final class DevFormatter implements FormatterInterface
    {
        public function format(string $text): string { return strtoupper($text); }
    }

    final class ReportController
    {
        public function __construct(#[Target('textFormatter')] private FormatterInterface $formatter) {}

        public function show(): string { return $this->formatter->format('report'); }
    }

    final class MissingController
    {
        public function __construct(#[Target('missingFormatter')] private FormatterInterface $formatter) {}
    }
}
