<?php

namespace ApiTypes\Support;

final class Ts
{
    /** Render a PHP value as a TypeScript literal. */
    public static function literal(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => "'".str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\\n'], (string) $value)."'",
        };
    }

    /** Render an object key, quoting it only when needed. */
    public static function key(string|int $key): string
    {
        $key = (string) $key;

        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) ? $key : self::literal($key);
    }

    /** Combine types into a de-duplicated union ('unknown' absorbs everything, null goes last). */
    public static function union(string ...$types): string
    {
        $parts = [];
        foreach ($types as $type) {
            foreach (self::parts($type) as $part) {
                $parts[$part] = true;
            }
        }

        if (isset($parts['unknown'])) {
            return 'unknown';
        }

        $nullable = isset($parts['null']);
        unset($parts['null'], $parts['never']);

        $out = array_keys($parts);
        if ($nullable) {
            $out[] = 'null';
        }

        return $out === [] ? 'never' : implode(' | ', $out);
    }

    public static function withoutNull(string $type): string
    {
        $parts = array_filter(self::parts($type), fn ($p) => $p !== 'null');

        return $parts === [] ? 'never' : implode(' | ', $parts);
    }

    public static function isNullable(string $type): bool
    {
        return in_array('null', self::parts($type), true);
    }

    /** Split a union on its top-level " | " only (not inside {...}, <...>, (...)). */
    public static function parts(string $type): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($type);

        for ($i = 0; $i < $length; $i++) {
            $char = $type[$i];
            $depth += match ($char) { '{', '<', '(', '[' => 1, '}', '>', ')', ']' => -1, default => 0 };

            if ($depth === 0 && substr($type, $i, 3) === ' | ') {
                $parts[] = trim($current);
                $current = '';
                $i += 2;

                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /** Wrap a type into an array type. */
    public static function arrayOf(string $type): string
    {
        return count(self::parts($type)) > 1 ? "Array<{$type}>" : "{$type}[]";
    }

    /** Render a property map as an object literal type. */
    public static function object(array $props): string
    {
        if ($props === []) {
            return 'Record<string, never>';
        }

        $lines = [];
        foreach ($props as $key => $prop) {
            $lines[] = '  '.self::key($key).($prop['optional'] ? '?' : '').': '
                .str_replace("\n", "\n  ", $prop['type']).';';
        }

        return "{\n".implode("\n", $lines)."\n}";
    }
}
