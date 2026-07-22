<?php

namespace NickWelsh\LaravelZero\Compiler\Inputs;

use BackedEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Enum;
use Stringable;

final class ZodRuleCompiler
{
    /** @var array<string, list<string>> */
    private array $serverOnly = [];

    /** @param array<string, mixed> $rules */
    public function object(array $rules, string $inputClass): string
    {
        $fields = [];
        foreach ($rules as $field => $definition) {
            if (str_contains($field, '.')) {
                continue;
            }
            $fieldRules = (array) (is_string($definition) ? explode('|', $definition) : $definition);
            $zod = $this->field($fieldRules, $inputClass.'.'.$field);
            if (in_array('array', array_map($this->name(...), $fieldRules), true)) {
                $zod = str_replace('z.array(z.unknown())', 'z.array('.$this->arrayElement($field, $rules, $inputClass).')', $zod);
            }
            $fields[] = $field.': '.$zod;
        }

        return 'z.object({'.implode(', ', $fields).'})';
    }

    /** @return array<string, list<string>> */
    public function notices(): array
    {
        ksort($this->serverOnly);

        return $this->serverOnly;
    }

    /** @param array<string, mixed> $rules */
    private function arrayElement(string $field, array $rules, string $inputClass): string
    {
        $wildcard = $field.'.*';
        if (array_key_exists($wildcard, $rules)) {
            $definition = $rules[$wildcard];

            return $this->field((array) (is_string($definition) ? explode('|', $definition) : $definition), $inputClass.'.'.$wildcard);
        }

        $children = [];
        foreach ($rules as $path => $definition) {
            if (! str_starts_with($path, $wildcard.'.')) {
                continue;
            }
            $child = substr($path, strlen($wildcard) + 1);
            if (str_contains($child, '.')) {
                continue;
            }
            $children[] = $child.': '.$this->field((array) (is_string($definition) ? explode('|', $definition) : $definition), $inputClass.'.'.$path);
        }

        return $children === [] ? 'z.json()' : 'z.object({'.implode(', ', $children).'})';
    }

    /** @param list<mixed> $rules */
    private function field(array $rules, string $path): string
    {
        $names = array_map(fn (mixed $rule): string => $this->name($rule), $rules);
        $type = 'z.json()';
        if (in_array('string', $names, true)) {
            $type = 'z.string()';
        }
        if (in_array('integer', $names, true)) {
            $type = 'z.number().int()';
        }
        if (in_array('numeric', $names, true)) {
            $type = 'z.number()';
        }
        if (in_array('boolean', $names, true)) {
            $type = 'z.boolean()';
        }
        if (in_array('array', $names, true)) {
            $type = 'z.array(z.unknown())';
        }

        foreach ($rules as $rule) {
            $name = $this->name($rule);
            [$base, $argument] = array_pad(explode(':', $name, 2), 2, null);
            $type .= match ($base) {
                'min' => str_starts_with($type, 'z.string') || str_starts_with($type, 'z.array') ? ".min({$argument})" : ".gte({$argument})",
                'max' => str_starts_with($type, 'z.string') || str_starts_with($type, 'z.array') ? ".max({$argument})" : ".lte({$argument})",
                'length', 'size' => ".length({$argument})",
                'email' => '.email()',
                'url' => '.url()',
                'uuid' => '.uuid()',
                'ulid' => '.ulid()',
                'date' => '.date()',
                'date_format' => '.refine(value => !Number.isNaN(Date.parse(value)))',
                'in' => '.refine(value => '.json_encode(explode(',', (string) $argument), JSON_THROW_ON_ERROR).'.includes(value as never))',
                default => '',
            };

            if (! in_array($base, ['required', 'sometimes', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array', 'min', 'max', 'length', 'size', 'email', 'url', 'uuid', 'ulid', 'date', 'date_format', 'in'], true)) {
                if ($rule instanceof Enum) {
                    $type = $this->enumRule($rule);
                } else {
                    $this->serverOnly[$path][] = $name;
                }
            }
        }

        if (in_array('nullable', $names, true)) {
            $type .= '.nullable()';
        }
        if (! in_array('required', $names, true) || in_array('sometimes', $names, true)) {
            $type .= '.optional()';
        }

        return $type;
    }

    private function name(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }
        if ($rule instanceof Stringable) {
            return (string) $rule;
        }
        if ($rule instanceof ValidationRule) {
            return $rule::class;
        }

        return get_debug_type($rule);
    }

    private function enumRule(Enum $rule): string
    {
        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('type');
        $enum = $property->getValue($rule);
        $values = array_map(fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR), $enum::cases());

        return 'z.enum(['.implode(', ', $values).'])';
    }
}
