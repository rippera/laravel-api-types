<?php

namespace ApiTypes\Attributes;

use Attribute;

/**
 * Overrides (or adds) a property of a JsonResource's generated interface.
 *
 *   #[TsProperty('settings', 'Record<string, boolean>')]
 *   class UserResource extends JsonResource { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class TsProperty
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $optional = false,
    ) {}
}
