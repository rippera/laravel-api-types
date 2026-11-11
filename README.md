# laravel-api-types

Artisan ბრძანება, რომელიც Laravel-ის API route-ებს, მათ FormRequest-ებს და PHP enum-ებს კითხულობს და TypeScript ფაილებს აგენერირებს.

```bash
php artisan api-types:generate
php artisan api-types:generate --path=frontend/src/api --enum-style=const
php artisan api-types:generate --check   # CI-სთვის: ჩავარდება, თუ ტიპები მოძველებულია
```

## ინსტალაცია

```bash
composer require rippera/laravel-api-types --dev
php artisan vendor:publish --tag=api-types-config
```

Sail-ში: `./vendor/bin/sail composer require ...` და `./vendor/bin/sail artisan api-types:generate`.

## რას აგენერირებს

| ფაილი          | შიგთავსი                                                                                                          |
| -------------- | ----------------------------------------------------------------------------------------------------------------- |
| `enums.ts`     | `app/Enums`-ში არსებული და `Rule::enum()`-ით გამოყენებული ყველა enum                                              |
| `requests.ts`  | თითო FormRequest-ზე ერთი `interface`                                                                              |
| `resources.ts` | API Resource-ების და მოდელების interface-ები + პასუხის wrapper-ები (`ResourceResponse`, `PaginatedResponse`, ...) |
| `endpoints.ts` | `Endpoints` ტიპი (method / params / query / body / **response**), `endpoints` const, `url()` helper               |
| `index.ts`     | ყველაფრის re-export                                                                                               |

## როგორ მუშაობს

1. `RouteCollector` — გადის `Route::getRoutes()`-ზე, ფილტრავს prefix-ით/სახელით, იღებს HTTP method-ებს და `{param}`/`{param?}` პარამეტრებს.
2. Controller-ის მეთოდს Reflection-ით ამოწმებს და პოულობს FormRequest-ის ტიპის პარამეტრს.
3. `RulesExtractor` — FormRequest-ს ქმნის container-ის გარეშე (რომ ვალიდაცია არ გაეშვას) და იძახებს `rules()`-ს.
4. `TypeMapper` — წესებს TS ტიპებად აქცევს (`required` → სავალდებულო, `nullable` → `| null`, `items.*.id` → `Array<{ id: number }>`, `Rule::enum` → enum, `in:` → literal union).
5. `TypeScriptWriter` — წერს ფაილებს.

## პასუხის ტიპები (response)

რას აბრუნებს action, დგინდება ამ თანმიმდევრობით:

1. `#[ApiResponse(...)]` ატრიბუტი controller-ის მეთოდზე
2. native return type: `): UserResource`, `): PostCollection`, `): User`
3. მეთოდის ტანი: `UserResource::collection(...)`, `UserResource::make(...)`, `new UserResource(...)`, `return $user` (type-hint-იანი Model), `noContent()`
   - `->paginate()` / `->simplePaginate()` / `->cursorPaginate()` → შესაბამისი paginated wrapper

Resource-ის ფორმა იკითხება `toArray()`-ის სტატიკური ანალიზით (nikic/php-parser):

- `$this->id` → ტიპი DB სქემიდან + `$casts`-დან (enum cast → TS enum, nullable სვეტი → `| null`)
- `XResource::collection($this->whenLoaded('x'))` → `x?: XResource[]`
- `$this->when(...)`, `whenLoaded`, `whenCounted`, `mergeWhen` → optional ველები
- `parent::toArray($request)` / `toArray()`-ის არქონა → მოდელის ყველა ატრიბუტი (`$hidden`-ის გარეშე, `$appends`-ით)
- მოდელი იძებნება `@mixin \App\Models\User` docblock-ით ან კონვენციით (`UserResource` → `App\Models\User`)

რისი დადგენაც ვერ ხერხდება, ხდება `unknown`. ასეთ ველს ხელით ასწორებ:

```php
#[TsProperty('settings', 'Record<string, boolean>')]
class UserResource extends JsonResource { ... }

#[ApiResponse(UserResource::class, paginated: true)]
public function index() { ... }

#[ApiResponse('{ ok: boolean; message: string }')]
public function ping() { ... }
```

> DB-ის სვეტების ტიპების წასაკითხად ბრძანებას DB კავშირი სჭირდება (`Schema::getColumns`). კავშირის გარეშე მხოლოდ `$casts` გამოიყენება.

## შეზღუდვები / შემდეგი ნაბიჯები

- Controller-ში inline `$request->validate([...])` არ იკითხება — მხოლოდ FormRequest.
- `rules()`, რომელიც `$this->route('user')->id`-ს იყენებს, route binding-ის გარეშე შეიძლება ჩავარდეს — ბრძანება warning-ს გამოიტანს და ტიპს ცარიელს დატოვებს.
- `response()->json([...])` ხელით აწყობილი მასივი არ ანალიზდება — გამოიყენე `#[ApiResponse('...')]`.
- `Attribute::make(get: ...)` ტიპის accessor-ების ტიპი არ იკითხება (მხოლოდ `getXAttribute(): string`).
- Resource-ის `with()` / `additional()` meta ველები არ ემატება.
