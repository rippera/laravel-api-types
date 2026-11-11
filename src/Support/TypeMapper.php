<?php

namespace ApiTypes\Support;

use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;
use ReflectionProperty;

/**
 * Turns Laravel validation rules into TypeScript types.
 *
 *   'name'          => 'required|string'      ->  name: string;
 *   'age'           => 'nullable|integer'     ->  age?: number | null;
 *   'status'        => [Rule::enum(Status::class)] -> status?: Status;
 *   'tags.*'        => 'string'               ->  tags?: string[];
 *   'items.*.qty'   => 'required|integer'     ->  items?: Array<{ qty: number }>;
 */
class TypeMapper
{
    private const STRING = [
        'string', 'email', 'url', 'active_url', 'uuid', 'ulid', 'ip', 'ipv4', 'ipv6', 'mac_address',
        'date', 'date_format', 'date_equals', 'before', 'after', 'before_or_equal', 'after_or_equal',
        'timezone', 'alpha', 'alpha_dash', 'alpha_num', 'ascii', 'json', 'regex', 'not_regex',
        'starts_with', 'ends_with', 'doesnt_start_with', 'doesnt_end_with', 'lowercase', 'uppercase',
        'hex_color', 'current_password',
    ];

    private const NUMBER = ['integer', 'numeric', 'decimal', 'multiple_of'];

    private const BOOLEAN = ['boolean', 'accepted', 'declined'];

    private const FILE = ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    /** @var array<string, class-string<\UnitEnum>> enums referenced by rules (TS name => class) */
    public array $usedEnums = [];

    public function __construct(private readonly array $config = []) {}

    /** @param  array<string, mixed>  $rules */
    public function objectType(array $rules, int $indent = 0): string
    {
        return $this->renderObject($this->buildTree($rules), $indent);
    }

    /**
     * 'items.*.name' => [...]  becomes  items -> * -> name
     */
    private function buildTree(array $rules): array
    {
        $tree = [];

        foreach ($rules as $field => $fieldRules) {
            $segments = explode('.', (string) $field);
            $last = count($segments) - 1;
            $node = &$tree;

            foreach ($segments as $i => $segment) {
                $node[$segment] ??= ['rules' => [], 'children' => []];

                if ($i === $last) {
                    $node[$segment]['rules'] = $this->normalize($fieldRules);
                } else {
                    $node = &$node[$segment]['children'];
                }
            }

            unset($node);
        }

        return $tree;
    }

    private function renderObject(array $tree, int $indent): string
    {
        unset($tree['*']);

        if ($tree === []) {
            return 'Record<string, never>';
        }

        $pad = str_repeat('  ', $indent + 1);
        $lines = [];

        foreach ($tree as $key => $node) {
            $lines[] = sprintf(
                '%s%s%s: %s;',
                $pad,
                Ts::key($key),
                $this->isRequired($node['rules']) ? '' : '?',
                $this->renderNode($node, $indent + 1),
            );
        }

        return "{\n".implode("\n", $lines)."\n".str_repeat('  ', $indent).'}';
    }

    private function renderNode(array $node, int $indent): string
    {
        $children = $node['children'];

        if (isset($children['*'])) {
            $item = $this->renderNode($children['*'], $indent);
            $type = str_contains($item, '|') ? "Array<{$item}>" : "{$item}[]";
        } elseif ($children !== []) {
            $type = $this->renderObject($children, $indent);
        } else {
            $type = $this->scalarType($node['rules']);
        }

        return $this->hasRule($node['rules'], 'nullable') ? "{$type} | null" : $type;
    }

    private function scalarType(array $rules): string
    {
        // Rules that describe an exact set of values win over generic type rules.
        foreach ($rules as $rule) {
            if ($rule instanceof EnumRule) {
                return $this->enumType($this->read($rule, 'type'));
            }

            if ($rule instanceof In) {
                return $this->union($this->read($rule, 'values'));
            }

            if (is_string($rule) && $this->ruleName($rule) === 'in') {
                return $this->union(str_getcsv(explode(':', $rule, 2)[1] ?? '', ',', '"', ''));
            }
        }

        foreach ($rules as $rule) {
            if ($rule instanceof FileRule) {
                return $this->config['file_type'] ?? 'File';
            }

            if ($rule instanceof Password) {
                return 'string';
            }

            if (! is_string($rule)) {
                continue;
            }

            $name = $this->ruleName($rule);

            switch (true) {
                case in_array($name, self::BOOLEAN, true): return 'boolean';
                case in_array($name, self::NUMBER, true): return 'number';
                case in_array($name, self::STRING, true): return 'string';
                case in_array($name, self::FILE, true): return $this->config['file_type'] ?? 'File';
                case $name === 'list': return 'unknown[]';
                case $name === 'array': return 'unknown[] | Record<string, unknown>';
            }
        }

        return $this->config['fallback_type'] ?? 'unknown';
    }

    /** @param  class-string<\UnitEnum>  $class */
    private function enumType(string $class): string
    {
        $name = class_basename($class);
        $this->usedEnums[$name] = $class;

        return $name;
    }

    private function union(array $values): string
    {
        $values = array_unique(array_map([Ts::class, 'literal'], $values));

        return $values === [] ? 'never' : implode(' | ', $values);
    }

    private function isRequired(array $rules): bool
    {
        return $this->hasRule($rules, 'required') || $this->hasRule($rules, 'present');
    }

    private function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && $this->ruleName($rule) === $name) {
                return true;
            }
        }

        return false;
    }

    private function ruleName(string $rule): string
    {
        return strtolower(trim(explode(':', $rule, 2)[0]));
    }

    private function normalize(mixed $rules): array
    {
        return match (true) {
            is_string($rules) => explode('|', $rules),
            is_array($rules) => array_values($rules),
            default => [$rules],
        };
    }

    private function read(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
