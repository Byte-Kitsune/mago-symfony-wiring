<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

interface ServiceMapLoader
{
    public function load(): ServiceMap;

    /** @return array<string, array<int, ?string>> Owner class to constructor position and concrete class, or null when unresolved. */
    public function constructorClassBindings(): array;
}
