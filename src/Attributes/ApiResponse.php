<?php

namespace ApiTypes\Attributes;

use Attribute;

/**
 * Explicitly declares what a controller action returns, when it can't be inferred.
 *
 *   #[ApiResponse(UserResource::class)]
 *   #[ApiResponse(UserResource::class, paginated: true)]
 *   #[ApiResponse(User::class, collection: true)]
 *   #[ApiResponse('{ ok: boolean; message: string }')]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiResponse
{
    /**
     * @param  string  $type  JsonResource class, Eloquent model class, or a raw TypeScript type
     * @param  bool|'length'|'simple'|'cursor'  $paginated  true = paginate(), or the paginator kind
     */
    public function __construct(
        public readonly string $type,
        public readonly bool $collection = false,
        public readonly bool|string $paginated = false,
    ) {}
}
