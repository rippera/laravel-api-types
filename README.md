# laravel-api-types

An Artisan command that reads your Laravel API routes, their FormRequests, API Resources and PHP enums, and generates TypeScript types from them.

```bash
php artisan api-types:generate
php artisan api-types:generate --path=frontend/src/api --enum-style=const
php artisan api-types:generate --check   # for CI: fails if the generated types are out of date
```

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13
- `nikic/php-parser` ^5.0 (installed automatically)

## Installation

```bash
composer require rippera/laravel-api-types --dev
php artisan vendor:publish --tag=api-types-config
```

With Sail: `./vendor/bin/sail composer require ...` and `./vendor/bin/sail artisan api-types:generate`.

## What it generates

| File           | Contents                                                                                                         |
| -------------- | ---------------------------------------------------------------------------------------------------------------- |
| `enums.ts`     | Every enum in `app/Enums` and every enum used via `Rule::enum()`                                                 |
| `requests.ts`  | One `interface` per FormRequest                                                                                  |
| `resources.ts` | Interfaces for API Resources and models, plus response wrappers (`ResourceResponse`, `PaginatedResponse`, ...)   |
| `endpoints.ts` | The `Endpoints` type (method / params / query / body / **response**), the `endpoints` const and a `url()` helper |
| `index.ts`     | Re-exports everything                                                                                            |

## How it works

1. `RouteCollector` iterates over `Route::getRoutes()`, filters routes by prefix and name, and collects HTTP methods and `{param}` / `{param?}` parameters.
2. It inspects the controller method via Reflection to find a FormRequest-typed parameter.
3. `RulesExtractor` instantiates the FormRequest without the container (so validation doesn't run) and calls `rules()`.
4. `TypeMapper` turns the rules into TypeScript types (`required` → required property, `nullable` → `| null`, `items.*.id` → `Array<{ id: number }>`, `Rule::enum` → enum, `in:` → literal union).
5. `TypeScriptWriter` writes the files.

## Response types

What an action returns is determined in this order:

1. An `#[ApiResponse(...)]` attribute on the controller method
2. The native return type: `): UserResource`, `): PostCollection`, `): User`
3. The method body: `UserResource::collection(...)`, `UserResource::make(...)`, `new UserResource(...)`, `return $user` (a type-hinted model), `noContent()`
   - `->paginate()` / `->simplePaginate()` / `->cursorPaginate()` → the matching paginated wrapper

The shape of a resource is inferred by statically analysing its `toArray()` method (using nikic/php-parser):

- `$this->id` → type taken from the DB schema and `$casts` (enum cast → TS enum, nullable column → `| null`)
- `XResource::collection($this->whenLoaded('x'))` → `x?: XResource[]`
- `$this->when(...)`, `whenLoaded`, `whenCounted`, `mergeWhen` → optional properties
- `parent::toArray($request)` or no `toArray()` at all → all model attributes (excluding `$hidden`, including `$appends`)
- The model is resolved from a `@mixin \App\Models\User` docblock, or by convention (`UserResource` → `App\Models\User`)

Anything that can't be inferred becomes `unknown`. You can fix such properties by hand:

```php
#[TsProperty('settings', 'Record<string, boolean>')]
class UserResource extends JsonResource { ... }

#[ApiResponse(UserResource::class, paginated: true)]
public function index() { ... }

#[ApiResponse('{ ok: boolean; message: string }')]
public function ping() { ... }
```

> Reading column types requires a database connection (`Schema::getColumns`). Without one, only `$casts` are used.

## Limitations / roadmap

- Inline `$request->validate([...])` calls in controllers are not read — only FormRequests.
- A `rules()` method that uses `$this->route('user')->id` may fail without route model binding — the command prints a warning and leaves that type empty.
- Hand-built arrays in `response()->json([...])` are not analysed — use `#[ApiResponse('...')]` instead.
- Types of `Attribute::make(get: ...)` accessors are not read (only `getXAttribute(): string`).
- A resource's `with()` / `additional()` meta fields are not included.

## License

MIT
