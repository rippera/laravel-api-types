<?php

namespace ApiTypes\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Reads attribute types of an Eloquent model from the DB schema + $casts,
 * and resolves relations to their related model.
 */
class ModelInspector
{
    /** @var array<class-string, array<string, string>> */
    private array $attributes = [];

    public function __construct(
        private readonly EnumRegistry $enums,
        private readonly array $config = [],
    ) {}

    /**
     * Shape of the model's toArray(): attributes minus $hidden, plus $appends.
     *
     * @return array<string, array{type: string, optional: bool}>
     */
    public function modelProps(string $class): array
    {
        $model = $this->instance($class);

        if ($model === null) {
            return [];
        }

        $hidden = array_flip($model->getHidden());
        $props = [];

        foreach ($this->attributes($class) as $name => $type) {
            if (! isset($hidden[$name])) {
                $props[$name] = ['type' => $type, 'optional' => false];
            }
        }

        foreach (method_exists($model, 'getAppends') ? $model->getAppends() : [] as $name) {
            $props[$name] = ['type' => $this->accessorType($class, $name) ?? $this->fallback(), 'optional' => false];
        }

        return $props;
    }

    public function attributeType(string $class, string $attribute): ?string
    {
        return $this->attributes($class)[$attribute] ?? $this->accessorType($class, $attribute);
    }

    /** @return array{model: class-string<Model>, many: bool}|null */
    public function relation(string $class, string $name): ?array
    {
        if (! method_exists($class, $name)) {
            return null;
        }

        $method = new ReflectionMethod($class, $name);
        $declaring = $method->getDeclaringClass()->getName();
        $returnType = $method->getReturnType();

        // Only call user-defined, parameterless methods that look like relations.
        if (str_starts_with($declaring, 'Illuminate\\')
            || $method->getNumberOfRequiredParameters() > 0
            || ($returnType instanceof ReflectionNamedType && ! is_a($returnType->getName(), Relation::class, true))) {
            return null;
        }

        try {
            $relation = $this->instance($class)?->{$name}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof Relation || $relation instanceof MorphTo) {
            return null;
        }

        return [
            'model' => get_class($relation->getRelated()),
            'many' => $relation instanceof HasMany
                || $relation instanceof MorphMany
                || $relation instanceof BelongsToMany
                || $relation instanceof HasManyThrough,
        ];
    }

    /** @return array<string, string> attribute => TS type */
    private function attributes(string $class): array
    {
        if (isset($this->attributes[$class])) {
            return $this->attributes[$class];
        }

        $model = $this->instance($class);

        if ($model === null) {
            return $this->attributes[$class] = [];
        }

        $attributes = [];

        foreach ($this->columns($model) as $column) {
            $attributes[$column['name']] = $this->columnType($column);
        }

        foreach ($model->getCasts() as $name => $cast) {
            $type = $this->castType((string) $cast);

            if ($type !== null) {
                $nullable = isset($attributes[$name]) && Ts::isNullable($attributes[$name]);
                $attributes[$name] = $nullable ? Ts::union($type, 'null') : $type;
            }
        }

        foreach (method_exists($model, 'getDates') ? $model->getDates() : [] as $date) {
            $attributes[$date] ??= 'string | null';
        }

        return $this->attributes[$class] = $attributes;
    }

    private function columns(Model $model): array
    {
        try {
            return $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return []; // no DB connection / old Laravel: rely on $casts only
        }
    }

    private function columnType(array $column): string
    {
        $name = strtolower($column['type_name'] ?? '');
        $full = strtolower($column['type'] ?? '');

        $type = match (true) {
            $full === 'tinyint(1)', in_array($name, ['bool', 'boolean'], true) => 'boolean',
            in_array($name, [
                'int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8',
                'serial', 'bigserial', 'float', 'double', 'real', 'float4', 'float8', 'double precision', 'year',
            ], true) => 'number',
            in_array($name, ['json', 'jsonb'], true) => 'unknown',
            $name === 'enum' && preg_match_all("/'([^']*)'/", $column['type'] ?? '', $m) > 0 => implode(' | ', array_map([Ts::class, 'literal'], $m[1])),
            default => 'string', // varchar, text, uuid, dates, decimal (PDO returns decimals as strings)
        };

        return ($column['nullable'] ?? false) ? Ts::union($type, 'null') : $type;
    }

    private function castType(string $cast): ?string
    {
        if (enum_exists($cast)) {
            return $this->enums->register($cast);
        }

        [$base, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        // AsEnumCollection::of(Status::class) / AsEnumArrayObject::of(...)
        if ($argument !== null && enum_exists($argument) && str_contains($base, 'Enum')) {
            return $this->enums->register($argument).'[]';
        }

        if (class_exists($base)) {
            return str_contains($base, 'AsCollection') || str_contains($base, 'AsArrayObject')
                ? 'unknown[] | Record<string, unknown>'
                : null; // custom cast: unknown shape
        }

        return match (strtolower($cast)) {
            'encrypted:array', 'encrypted:collection', 'encrypted:object' => 'unknown[] | Record<string, unknown>',
            default => match (strtolower($base)) {
                'int', 'integer', 'timestamp', 'real', 'float', 'double' => 'number',
                'bool', 'boolean' => 'boolean',
                'string', 'decimal', 'encrypted', 'hashed',
                'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime' => 'string',
                'array', 'json', 'object', 'collection' => 'unknown[] | Record<string, unknown>',
                default => null,
            },
        };
    }

    /** Old-style accessor with a native return type: getFullNameAttribute(): string */
    private function accessorType(string $class, string $attribute): ?string
    {
        $method = 'get'.Str::studly($attribute).'Attribute';

        return method_exists($class, $method)
            ? $this->reflectionType((new ReflectionMethod($class, $method))->getReturnType())
            : null;
    }

    private function reflectionType(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $out = [];

        foreach ($types as $named) {
            if (! $named instanceof ReflectionNamedType) {
                return null;
            }

            $name = $named->getName();
            $out[] = match (true) {
                in_array($name, ['int', 'float'], true) => 'number',
                in_array($name, ['bool', 'true', 'false'], true) => 'boolean',
                $name === 'string' => 'string',
                $name === 'null' => 'null',
                $name === 'array' => 'unknown[] | Record<string, unknown>',
                enum_exists($name) => $this->enums->register($name),
                default => $this->fallback(),
            };

            if ($name !== 'null' && $name !== 'mixed' && $named->allowsNull()) {
                $out[] = 'null';
            }
        }

        return Ts::union(...$out);
    }

    private function instance(string $class): ?Model
    {
        try {
            return new $class;
        } catch (Throwable) {
            return null;
        }
    }

    private function fallback(): string
    {
        return $this->config['fallback_type'] ?? 'unknown';
    }
}
