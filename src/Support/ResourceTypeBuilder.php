<?php

namespace ApiTypes\Support;

use ApiTypes\Attributes\TsProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Infers TypeScript interfaces for API Resources by statically reading toArray():
 *
 *   'id'         => $this->id,                                   -> id: number            (DB schema / $casts)
 *   'status'     => $this->status,                               -> status: UserStatus    (enum cast)
 *   'created_at' => $this->created_at?->toIsoString(),           -> created_at: string | null
 *   'posts'      => PostResource::collection($this->whenLoaded('posts')) -> posts?: PostResource[]
 *   'secret'     => $this->when($isAdmin, $this->secret),        -> secret?: string
 */
class ResourceTypeBuilder
{
    /** @var array<class-string, string> */
    private array $resources = [];

    /** @var array<class-string, string> */
    private array $models = [];

    public function __construct(
        private readonly ModelInspector $inspector,
        private readonly EnumRegistry $enums,
        private readonly array $config = [],
    ) {}

    /** Register a resource for rendering and get its TS name. */
    public function resource(string $class): string
    {
        return $this->resources[$class] ??= $this->uniqueName(class_basename($class));
    }

    /** Register a model for rendering and get its TS name. */
    public function model(string $class): string
    {
        return $this->models[$class] ??= $this->uniqueName(class_basename($class));
    }

    /** @return string[] */
    public function names(): array
    {
        return array_merge(array_values($this->resources), array_values($this->models));
    }

    /**
     * Render every registered type, including ones discovered while rendering.
     *
     * @return array<string, string> TS name => declaration
     */
    public function render(): array
    {
        $done = [];

        do {
            $progress = false;

            foreach ($this->resources as $class => $name) {
                if (! isset($done[$name])) {
                    $done[$name] = $this->declaration($name, $this->resourceType($class));
                    $progress = true;
                }
            }

            foreach ($this->models as $class => $name) {
                if (! isset($done[$name])) {
                    $done[$name] = $this->declaration($name, Ts::object($this->inspector->modelProps($class)));
                    $progress = true;
                }
            }
        } while ($progress);

        ksort($done);

        return $done;
    }

    /** The resource a ResourceCollection collects (via $collects or naming convention). */
    public static function collects(string $collection): ?string
    {
        $collects = (new ReflectionClass($collection))->getDefaultProperties()['collects'] ?? null;

        $candidates = array_filter([
            $collects,
            str_ends_with($collection, 'Collection') ? substr($collection, 0, -10) : null,
            str_ends_with($collection, 'Collection') ? substr($collection, 0, -10).'Resource' : null,
        ]);

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, JsonResource::class)) {
                return $candidate;
            }
        }

        return null;
    }

    private function declaration(string $name, string $type): string
    {
        return str_starts_with($type, '{')
            ? "export interface {$name} {$type}"
            : "export type {$name} = {$type};";
    }

    private function resourceType(string $class): string
    {
        if (is_subclass_of($class, ResourceCollection::class)) {
            $collects = self::collects($class);

            return $collects ? $this->resource($collects).'[]' : 'unknown[]';
        }

        $props = $this->resourceProps($class);

        return $props === null ? 'Record<string, unknown>' : Ts::object($props);
    }

    private function resourceProps(string $class): ?array
    {
        $file = PhpFile::forClass($class);
        $node = $file?->findClass($class);
        $model = $this->guessModel($class, $file, $node);
        $method = $node?->getMethod('toArray');

        if ($method === null) {
            // No toArray(): inherited from a parent resource, or JsonResource's default (= the model).
            $parent = get_parent_class($class);
            $props = $parent && $parent !== JsonResource::class && is_subclass_of($parent, JsonResource::class)
                ? $this->resourceProps($parent)
                : ($model ? $this->inspector->modelProps($model) : null);
        } else {
            $return = (new NodeFinder)->findFirstInstanceOf($method->stmts ?? [], Return_::class);
            $props = $return?->expr ? $this->returnProps($return->expr, $model) : null;
        }

        foreach ((new ReflectionClass($class))->getAttributes(TsProperty::class) as $attribute) {
            $override = $attribute->newInstance();
            $props ??= [];
            $props[$override->name] = ['type' => $override->type, 'optional' => $override->optional];
        }

        return $props;
    }

    private function returnProps(Expr $expr, ?string $model): ?array
    {
        if ($expr instanceof Expr\Array_) {
            return $this->arrayProps($expr, $model);
        }

        // parent::toArray($request) / $this->resource->toArray()
        if ($this->isModelToArray($expr)) {
            return $model ? $this->inspector->modelProps($model) : [];
        }

        if ($expr instanceof Expr\FuncCall && $this->funcName($expr) === 'array_merge') {
            $props = [];
            foreach ($expr->getArgs() as $arg) {
                $props = array_merge($props, $this->returnProps($arg->value, $model) ?? []);
            }

            return $props;
        }

        return null;
    }

    private function arrayProps(Expr\Array_ $array, ?string $model): array
    {
        $props = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key === null) {
                $props = array_merge($props, $item->unpack
                    ? ($this->returnProps($item->value, $model) ?? [])
                    : $this->mergeProps($item->value, $model));

                continue;
            }

            $key = match (true) {
                $item->key instanceof Scalar\String_, $item->key instanceof Scalar\Int_ => (string) $item->key->value,
                default => null,
            };

            if ($key !== null) {
                [$type, $optional] = $this->exprType($item->value, $model);
                $props[$key] = ['type' => $type, 'optional' => $optional];
            }
        }

        return $props;
    }

    /** $this->merge([...]), $this->mergeWhen($cond, [...]) */
    private function mergeProps(Expr $expr, ?string $model): array
    {
        if (! $expr instanceof Expr\MethodCall || ! $this->isThis($expr->var) || ! $expr->name instanceof Identifier) {
            return [];
        }

        $name = strtolower($expr->name->toString());
        $args = $expr->getArgs();
        $value = match ($name) {
            'merge' => $args[0]->value ?? null,
            'mergewhen', 'mergeunless' => $args[1]->value ?? null,
            default => null,
        };

        $value = $this->unwrapClosure($value);

        if (! $value instanceof Expr\Array_) {
            return [];
        }

        $props = $this->arrayProps($value, $model);

        if ($name !== 'merge') {
            foreach ($props as &$prop) {
                $prop['optional'] = true;
            }
        }

        return $props;
    }

    /** @return array{0: string, 1: bool} [type, optional] */
    private function exprType(Expr $e, ?string $model): array
    {
        if ($e instanceof Expr\ArrowFunction || $e instanceof Expr\Closure) {
            $inner = $this->unwrapClosure($e);

            return $inner ? $this->exprType($inner, $model) : [$this->fallback(), false];
        }

        // $this->name / $this->resource->name
        if (($attribute = $this->thisAttribute($e)) !== null) {
            return [$this->attributeType($attribute, $model), false];
        }

        // $this->status->value / ->name on enum attributes
        if (($e instanceof Expr\PropertyFetch || $e instanceof Expr\NullsafePropertyFetch)
            && $e->name instanceof Identifier && in_array($e->name->toString(), ['value', 'name'], true)) {
            [$inner] = $this->exprType($e->var, $model);
            $base = Ts::withoutNull($inner);

            if ($this->enums->has($base)) {
                $type = $e->name->toString() === 'value' ? $base : 'string';
                $nullable = Ts::isNullable($inner) || $e instanceof Expr\NullsafePropertyFetch;

                return [$nullable ? Ts::union($type, 'null') : $type, false];
            }

            return [$this->fallback(), false];
        }

        // $this->when(...), $this->whenLoaded(...), ...
        if ($e instanceof Expr\MethodCall && $this->isThis($e->var) && $e->name instanceof Identifier) {
            return $this->conditionalType(strtolower($e->name->toString()), $e->getArgs(), $model);
        }

        if ($e instanceof Expr\New_ && $e->class instanceof Name) {
            return $this->resourceCall($e->class->toString(), 'make', $e->getArgs()) ?? [$this->fallback(), false];
        }

        if ($e instanceof Expr\StaticCall && $e->class instanceof Name && $e->name instanceof Identifier) {
            $class = $e->class->toString();
            $method = strtolower($e->name->toString());

            if ($result = $this->resourceCall($class, $method, $e->getArgs())) {
                return $result;
            }

            if (enum_exists($class) && in_array($method, ['from', 'tryfrom'], true)) {
                $name = $this->enums->register($class);

                return [$method === 'tryfrom' ? "{$name} | null" : $name, false];
            }

            return [$this->fallback(), false];
        }

        return [$this->simpleType($e, $model), false];
    }

    /** Literals, operators, casts and well-known method/function calls. */
    private function simpleType(Expr $e, ?string $model): string
    {
        $type = fn (Expr $x) => $this->exprType($x, $model)[0];

        return match (true) {
            $e instanceof Scalar\String_, $e instanceof Scalar\InterpolatedString,
            $e instanceof Expr\Cast\String_, $e instanceof Expr\BinaryOp\Concat => 'string',
            $e instanceof Scalar\Int_, $e instanceof Scalar\Float_,
            $e instanceof Expr\Cast\Int_, $e instanceof Expr\Cast\Double,
            $e instanceof Expr\BinaryOp\Plus, $e instanceof Expr\BinaryOp\Minus, $e instanceof Expr\BinaryOp\Mul,
            $e instanceof Expr\BinaryOp\Div, $e instanceof Expr\BinaryOp\Mod, $e instanceof Expr\BinaryOp\Pow,
            $e instanceof Expr\BinaryOp\Spaceship => 'number',
            $e instanceof Expr\Cast\Bool_, $e instanceof Expr\BooleanNot, $e instanceof Expr\Instanceof_,
            $e instanceof Expr\Isset_, $e instanceof Expr\Empty_ => 'boolean',
            $e instanceof Expr\BinaryOp\Coalesce => Ts::union(Ts::withoutNull($type($e->left)), $type($e->right)),
            $e instanceof Expr\BinaryOp => 'boolean', // comparisons and logical operators
            $e instanceof Expr\Ternary => Ts::union($type($e->if ?? $e->cond), $type($e->else)),
            $e instanceof Expr\Match_ => Ts::union(...array_map(fn ($arm) => $type($arm->body), $e->arms)),
            $e instanceof Expr\Array_ => $this->arrayLiteralType($e, $model),
            $e instanceof Expr\ConstFetch => match (strtolower($e->name->toString())) {
                'true', 'false' => 'boolean',
                'null' => 'null',
                default => $this->fallback(),
            },
            ($e instanceof Expr\MethodCall || $e instanceof Expr\NullsafeMethodCall) && $e->name instanceof Identifier
                && ($method = $this->methodReturnType(strtolower($e->name->toString()))) !== null
                => $e instanceof Expr\NullsafeMethodCall ? Ts::union($method, 'null') : $method,
            $e instanceof Expr\FuncCall && ($function = $this->functionReturnType($this->funcName($e))) !== null => $function,
            default => $this->fallback(),
        };
    }

    /** @return array{0: string, 1: bool} */
    private function conditionalType(string $method, array $args, ?string $model): array
    {
        $arg = fn (int $i) => $args[$i]->value ?? null;

        switch ($method) {
            case 'when':
            case 'unless':
                if ($arg(1) === null) {
                    return [$this->fallback(), true];
                }
                $type = $this->exprType($arg(1), $model)[0];

                return $arg(2) !== null
                    ? [Ts::union($type, $this->exprType($arg(2), $model)[0]), false]
                    : [$type, true];

            case 'whenloaded':
                return $arg(1) !== null
                    ? [$this->exprType($arg(1), $model)[0], true]
                    : [$this->relationType($this->stringArg($arg(0)), $model), true];

            case 'whencounted':
                return ['number', true];

            case 'whenaggregated':
                return ['number | null', true];

            case 'whenexistsloaded':
                return ['boolean', true];

            case 'whennotnull':
                return [$arg(0) ? Ts::withoutNull($this->exprType($arg(0), $model)[0]) : $this->fallback(), true];

            case 'whenhas':
                if ($arg(1) !== null) {
                    return [$this->exprType($arg(1), $model)[0], true];
                }
                $attribute = $this->stringArg($arg(0));

                return [$attribute ? $this->attributeType($attribute, $model) : $this->fallback(), true];

            case 'whenpivotloaded':
            case 'whenpivotloadedas':
                return [$this->fallback(), true];
        }

        return [$this->fallback(), false];
    }

    /** UserResource::collection(...), UserResource::make(...), new UserResource(...) */
    private function resourceCall(string $class, string $method, array $args): ?array
    {
        if (! class_exists($class) || ! is_subclass_of($class, JsonResource::class)) {
            return null;
        }

        $optional = isset($args[0]) && $this->isConditional($args[0]->value);

        if (is_subclass_of($class, ResourceCollection::class)) {
            $collects = self::collects($class);

            return [Ts::arrayOf($collects ? $this->resource($collects) : 'unknown'), $optional];
        }

        return match ($method) {
            'collection' => [$this->resource($class).'[]', $optional],
            'make' => [$this->resource($class), $optional],
            default => null,
        };
    }

    private function attributeType(string $attribute, ?string $model): string
    {
        if ($model === null) {
            return $this->fallback();
        }

        return $this->inspector->attributeType($model, $attribute)
            ?? ($this->inspector->relation($model, $attribute) ? $this->relationType($attribute, $model) : $this->fallback());
    }

    private function relationType(?string $relation, ?string $model): string
    {
        $info = $relation && $model ? $this->inspector->relation($model, $relation) : null;

        if ($info === null) {
            return $this->fallback();
        }

        $name = $this->model($info['model']);

        return $info['many'] ? "{$name}[]" : "{$name} | null";
    }

    private function arrayLiteralType(Expr\Array_ $array, ?string $model): string
    {
        foreach ($array->items as $item) {
            if ($item?->key !== null) {
                return Ts::object($this->arrayProps($array, $model));
            }
        }

        if ($array->items === []) {
            return 'unknown[]';
        }

        $types = array_map(fn ($item) => $this->exprType($item->value, $model)[0], array_filter($array->items));

        return Ts::arrayOf(Ts::union(...$types));
    }

    private function methodReturnType(string $method): ?string
    {
        return match ($method) {
            'toisostring', 'todatetimestring', 'todatestring', 'totimestring', 'toatomstring', 'tojson',
            'format', 'diffforhumans', 'tostring', '__tostring', 'toformatteddatestring', 'translatedformat' => 'string',
            'count', 'sum', 'avg', 'gettimestamp' => 'number',
            'isempty', 'isnotempty', 'ispast', 'isfuture', 'istoday', 'exists', 'contains' => 'boolean',
            'toarray', 'all', 'pluck', 'values', 'keys' => 'unknown[]',
            default => null,
        };
    }

    private function functionReturnType(string $function): ?string
    {
        return match ($function) {
            'count', 'strlen', 'mb_strlen', 'intval', 'floatval', 'round', 'floor', 'ceil', 'abs',
            'array_sum', 'max', 'min', 'str_word_count' => 'number',
            'strval', 'sprintf', 'implode', 'trim', 'ucfirst', 'strtolower', 'strtoupper', 'number_format',
            '__', 'trans', 'route', 'url', 'asset', 'json_encode', 'e', 'str' => 'string',
            'boolval', 'is_null', 'is_numeric', 'in_array', 'array_key_exists', 'filled', 'blank' => 'boolean',
            'array_values', 'array_keys', 'array_map', 'array_filter' => 'unknown[]',
            default => null,
        };
    }

    private function guessModel(string $class, ?PhpFile $file, ?Class_ $node): ?string
    {
        // 1. /** @mixin \App\Models\User */
        $doc = $node?->getDocComment()?->getText() ?? '';
        if ($file && preg_match('/@mixin\s+([\\\\\w]+)/', $doc, $match)) {
            $candidate = $file->resolveName($match[1]);
            if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        // 2. Convention: UserResource -> App\Models\User
        $base = class_basename($class);
        $base = str_ends_with($base, 'Resource') ? substr($base, 0, -8) : $base;

        foreach ($this->config['responses']['model_namespaces'] ?? ['App\\Models\\'] as $namespace) {
            $candidate = rtrim($namespace, '\\').'\\'.$base;
            if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        return null;
    }

    private function thisAttribute(Expr $e): ?string
    {
        if (! ($e instanceof Expr\PropertyFetch || $e instanceof Expr\NullsafePropertyFetch) || ! $e->name instanceof Identifier) {
            return null;
        }

        $name = $e->name->toString();

        if ($this->isThis($e->var)) {
            return $name === 'resource' ? null : $name;
        }

        $isResource = $e->var instanceof Expr\PropertyFetch && $this->isThis($e->var->var)
            && $e->var->name instanceof Identifier && $e->var->name->toString() === 'resource';

        return $isResource ? $name : null;
    }

    private function isModelToArray(Expr $e): bool
    {
        $name = ($e instanceof Expr\StaticCall || $e instanceof Expr\MethodCall) && $e->name instanceof Identifier
            ? strtolower($e->name->toString()) : null;

        if ($name !== 'toarray') {
            return false;
        }

        return ($e instanceof Expr\StaticCall && $e->class instanceof Name && strtolower($e->class->toString()) === 'parent')
            || ($e instanceof Expr\MethodCall && $this->thisAttribute($e->var) === null
                && $e->var instanceof Expr\PropertyFetch && $this->isThis($e->var->var));
    }

    private function isConditional(Expr $e): bool
    {
        if (! $e instanceof Expr\MethodCall || ! $this->isThis($e->var) || ! $e->name instanceof Identifier) {
            return false;
        }

        $name = strtolower($e->name->toString());

        return str_starts_with($name, 'when') || $name === 'unless';
    }

    private function isThis(Expr $e): bool
    {
        return $e instanceof Expr\Variable && $e->name === 'this';
    }

    private function unwrapClosure(?Expr $e): ?Expr
    {
        if ($e instanceof Expr\ArrowFunction) {
            return $e->expr;
        }

        if ($e instanceof Expr\Closure) {
            return (new NodeFinder)->findFirstInstanceOf($e->stmts, Return_::class)?->expr;
        }

        return $e;
    }

    private function stringArg(?Expr $e): ?string
    {
        return $e instanceof Scalar\String_ ? $e->value : null;
    }

    private function funcName(Expr\FuncCall $call): string
    {
        return $call->name instanceof Name ? strtolower($call->name->getLast()) : '';
    }

    private function uniqueName(string $base): string
    {
        $taken = $this->names();
        $name = $base;
        $i = 2;

        while (in_array($name, $taken, true)) {
            $name = $base.$i++;
        }

        return $name;
    }

    private function fallback(): string
    {
        return $this->config['fallback_type'] ?? 'unknown';
    }
}
