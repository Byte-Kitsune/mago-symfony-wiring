<?php

declare(strict_types=1);

namespace ResourceFixture;

final class Consumer
{
    public function __construct(public FormatterInterface $formatter) {}
}
