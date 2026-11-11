<?php

namespace ApiTypes\Data;

final class Endpoint
{
    /**
     * @param  string[]  $methods  HTTP methods (HEAD excluded)
     * @param  array<string, bool>  $uriParams  parameter name => is optional
     * @param  class-string|null  $requestClass  FormRequest used by the controller action
     * @param  array<string, mixed>  $rules  validation rules of that FormRequest
     * @param  ResponseDefinition|null  $response  what the action returns, if it could be determined
     */
    public function __construct(
        public readonly string $key,
        public readonly array $methods,
        public readonly string $uri,
        public readonly array $uriParams,
        public readonly ?string $requestClass,
        public readonly array $rules,
        public readonly ?ResponseDefinition $response = null,
    ) {}

    public function isReadOnly(): bool
    {
        return array_diff($this->methods, ['GET']) === [];
    }
}
