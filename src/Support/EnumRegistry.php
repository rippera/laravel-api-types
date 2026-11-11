<?php

namespace ApiTypes\Support;

final class EnumRegistry
{
    /** @var array<string, class-string<\UnitEnum>> TS name => enum class */
    private array $enums = [];

    /** @param  class-string<\UnitEnum>  $class */
    public function register(string $class): string
    {
        $name = class_basename($class);
        $this->enums[$name] = $class;

        return $name;
    }

    public function has(string $name): bool
    {
        return isset($this->enums[$name]);
    }

    /** @return array<string, class-string<\UnitEnum>> */
    public function all(): array
    {
        return $this->enums;
    }
}
