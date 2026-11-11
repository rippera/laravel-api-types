<?php

namespace ApiTypes\Data;

final class ResponseDefinition
{
    public const RESOURCE = 'resource';

    public const MODEL = 'model';

    public const RAW = 'raw';

    /**
     * @param  self::RESOURCE|self::MODEL|self::RAW  $kind
     * @param  string  $type  resource class, model class or raw TS type
     * @param  'length'|'simple'|'cursor'|null  $pagination
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $type,
        public readonly bool $collection = false,
        public readonly ?string $pagination = null,
    ) {}
}
