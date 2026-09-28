<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ExcludedByAttribute implements FormatterInterface {}
