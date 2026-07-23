<?php

namespace NickWelsh\LaravelZero\Compiler\Inputs;

use BackedEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Stringable;

final class ZodRuleCompiler
{
    /** @var array<string, list<string>> */
    private array $serverOnly = [];

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    public function object(array $rules, string $inputClass, array $messages = []): string
    {
        $fields = [];
        $refinements = [];
        foreach ($rules as $field => $definition) {
            if (str_contains($field, '.')) {
                continue;
            }
            $fieldRules = $this->rules($definition);
            $expandedFieldRules = $this->expandPasswordRules($fieldRules);
            $zod = $this->field($fieldRules, $inputClass.'.'.$field, $field, $messages);
            if (in_array('array', array_map($this->baseName(...), $fieldRules), true)) {
                $zod = str_replace('z.array(z.any()', 'z.array('.$this->arrayElement($field, $rules, $inputClass, $messages), $zod);
            }
            $fields[$field] = $zod;

            foreach ($expandedFieldRules as $rule) {
                [$name, $argument] = $this->rule($rule);
                if ($name === 'confirmed') {
                    $other = $argument ?: $field.'_confirmation';
                    if (str_contains($other, '.')) {
                        $this->serverOnly[$inputClass.'.'.$field][] = $this->name($rule);
                    } else {
                        if (! array_key_exists($other, $rules)) {
                            $fields[$other] = 'z.any().optional()';
                        }
                        $refinements[] = $this->comparisonRefinement($field, $other, 'confirmed', $expandedFieldRules, $messages);
                    }
                }
                if ($name === 'same' && $argument) {
                    if (str_contains($argument, '.')) {
                        $this->serverOnly[$inputClass.'.'.$field][] = $this->name($rule);
                    } else {
                        if (! array_key_exists($argument, $rules)) {
                            $fields[$argument] = 'z.any().optional()';
                        }
                        $refinements[] = $this->comparisonRefinement($field, $argument, 'same', $expandedFieldRules, $messages);
                    }
                }
            }
        }

        $shape = implode(', ', array_map(
            fn (string $field, string $zod): string => $field.': '.$zod,
            array_keys($fields),
            array_values($fields),
        ));

        return 'z.object({'.$shape.'})'.implode('', $refinements);
    }

    /** @return array<string, list<string>> */
    public function notices(): array
    {
        ksort($this->serverOnly);

        return $this->serverOnly;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    private function arrayElement(string $field, array $rules, string $inputClass, array $messages): string
    {
        $wildcard = $field.'.*';
        if (array_key_exists($wildcard, $rules)) {
            return $this->field($this->rules($rules[$wildcard]), $inputClass.'.'.$wildcard, $wildcard, $messages);
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
            $children[] = $child.': '.$this->field($this->rules($definition), $inputClass.'.'.$path, $path, $messages);
        }

        return $children === [] ? 'z.any()' : 'z.object({'.implode(', ', $children).'})';
    }

    /**
     * @param  list<mixed>  $rules
     * @param  array<string, string>  $messages
     */
    private function field(array $rules, string $path, string $field, array $messages): string
    {
        $rules = $this->expandPasswordRules($rules);
        $names = array_map(fn (mixed $rule): string => $this->baseName($rule), $rules);
        $requiredMessage = $this->message($messages, $field, 'required');
        $enum = current(array_filter($rules, fn (mixed $rule): bool => $rule instanceof Enum)) ?: null;

        if ($enum instanceof Enum) {
            $type = $this->enumRule($enum, $this->schemaError($this->message($messages, $field, 'enum'), $requiredMessage));
        } elseif (in_array('date', $names, true)) {
            $type = $this->schema('z.coerce.date', [], $this->schemaError($this->message($messages, $field, 'date'), $requiredMessage));
        } elseif (in_array('email', $names, true)) {
            $type = $this->schema('z.email', [], $this->schemaError($this->message($messages, $field, 'email'), $requiredMessage));
        } elseif (in_array('url', $names, true)) {
            $type = $this->schema('z.url', [], $this->schemaError($this->message($messages, $field, 'url'), $requiredMessage));
        } elseif (in_array('uuid', $names, true)) {
            $type = $this->schema('z.uuid', [], $this->schemaError($this->message($messages, $field, 'uuid'), $requiredMessage));
        } elseif (in_array('ulid', $names, true)) {
            $type = $this->schema('z.ulid', [], $this->schemaError($this->message($messages, $field, 'ulid'), $requiredMessage));
        } elseif (in_array('date_format', $names, true)) {
            $type = $this->schema('z.string', [], $this->schemaError($this->message($messages, $field, 'date_format'), $requiredMessage));
        } elseif (in_array('string', $names, true)) {
            $type = $this->schema('z.string', [], $this->schemaError($this->message($messages, $field, 'string'), $requiredMessage));
        } elseif (in_array('integer', $names, true)) {
            $type = $this->schema('z.number', [], $this->schemaError($this->message($messages, $field, 'integer'), $requiredMessage)).'.int()';
        } elseif (in_array('numeric', $names, true)) {
            $type = $this->schema('z.number', [], $this->schemaError($this->message($messages, $field, 'numeric'), $requiredMessage));
        } elseif (in_array('boolean', $names, true)) {
            $type = $this->schema('z.boolean', [], $this->schemaError($this->message($messages, $field, 'boolean'), $requiredMessage));
        } elseif (in_array('array', $names, true)) {
            $type = $this->schema('z.array', ['z.any()'], $this->schemaError($this->message($messages, $field, 'array'), $requiredMessage));
        } else {
            $type = 'z.any()';
            if (in_array('required', $names, true)) {
                $message = $requiredMessage ?? 'Required';
                $type .= '.refine(value => value !== undefined, '.$this->error(['error' => $message]).')';
            }
        }

        $stringLike = array_intersect($names, ['string', 'email', 'url', 'uuid', 'ulid', 'date_format']) !== [];
        $arrayLike = in_array('array', $names, true);
        $numberLike = array_intersect($names, ['integer', 'numeric']) !== [];

        foreach ($rules as $rule) {
            [$base, $argument] = $this->rule($rule);
            $type .= match (true) {
                $base === 'min' && ($stringLike || $arrayLike) => ".min({$argument}".$this->checkError($this->message($messages, $field, 'min', [':min' => (string) $argument])).')',
                $base === 'min' && $numberLike => ".gte({$argument}".$this->checkError($this->message($messages, $field, 'min', [':min' => (string) $argument])).')',
                $base === 'max' && ($stringLike || $arrayLike) => ".max({$argument}".$this->checkError($this->message($messages, $field, 'max', [':max' => (string) $argument])).')',
                $base === 'max' && $numberLike => ".lte({$argument}".$this->checkError($this->message($messages, $field, 'max', [':max' => (string) $argument])).')',
                in_array($base, ['length', 'size'], true) => ".length({$argument}".$this->checkError($this->message($messages, $field, $base, [':size' => (string) $argument])).')',
                $base === 'date_format' => '.refine(value => !Number.isNaN(Date.parse(value))'.$this->checkError($this->message($messages, $field, 'date_format', [':format' => (string) $argument])).')',
                $base === 'in' => '.refine(value => '.json_encode(explode(',', (string) $argument), JSON_THROW_ON_ERROR).'.includes(value as never)'.$this->checkError($this->message($messages, $field, 'in')).')',
                default => '',
            };

            $portable = $rule instanceof Password
                || in_array($base, ['required', 'sometimes', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array', 'min', 'max', 'length', 'size', 'email', 'url', 'uuid', 'ulid', 'date', 'date_format', 'in'], true)
                || (in_array($base, ['confirmed', 'same'], true) && ! str_contains($field, '.'));
            if (! $portable && ! $rule instanceof Enum) {
                $this->serverOnly[$path][] = $this->name($rule);
            }
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Password) {
                $type .= $this->passwordChecks($rule, $path, $field, $messages);
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

    /**
     * @param  list<mixed>  $rules
     * @param  array<string, string>  $messages
     */
    private function comparisonRefinement(string $field, string $other, string $rule, array $rules, array $messages): string
    {
        $names = array_map(fn (mixed $item): string => $this->baseName($item), $rules);
        $value = 'data['.json_encode($field, JSON_THROW_ON_ERROR).']';
        $otherValue = 'data['.json_encode($other, JSON_THROW_ON_ERROR).']';
        $conditions = [];
        if (! in_array('required', $names, true) || in_array('sometimes', $names, true)) {
            $conditions[] = $value.' === undefined';
        }
        if (in_array('nullable', $names, true)) {
            $conditions[] = $value.' === null';
        }
        $conditions[] = $value.' === '.$otherValue;

        $message = $this->message($messages, $field, $rule, [':other' => $this->label($other)])
            ?? ($rule === 'confirmed'
                ? 'The '.$this->label($field).' field confirmation does not match.'
                : 'The '.$this->label($field).' field must match '.$this->label($other).'.');
        $errorField = $rule === 'confirmed' ? $other : $field;

        return '.refine(data => '.implode(' || ', $conditions).', '.$this->error([
            'error' => $message,
            'path' => [$errorField],
        ]).')';
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private function expandPasswordRules(array $rules): array
    {
        $expanded = [];
        foreach ($rules as $rule) {
            if (! $rule instanceof Password) {
                $expanded[] = $rule;

                continue;
            }

            $configuration = $this->passwordConfiguration($rule);
            if ($configuration['required']) {
                $expanded[] = 'required';
            }
            if ($configuration['sometimes']) {
                $expanded[] = 'sometimes';
            }
            $expanded[] = 'string';
            $expanded[] = 'min:'.$configuration['min'];
            if ($configuration['max'] !== null) {
                $expanded[] = 'max:'.$configuration['max'];
            }
            array_push($expanded, ...$configuration['customRules']);
            $expanded[] = $rule;
        }

        return $expanded;
    }

    /**
     * @return array{
     *     min: int,
     *     max: int|null,
     *     required: bool,
     *     sometimes: bool,
     *     mixedCase: bool,
     *     letters: bool,
     *     numbers: bool,
     *     symbols: bool,
     *     uncompromised: bool,
     *     compromisedThreshold: int,
     *     customRules: list<mixed>
     * }
     */
    private function passwordConfiguration(Password $rule): array
    {
        $reflection = new \ReflectionClass($rule);
        $method = $reflection->hasMethod('appliedRules') ? $reflection->getMethod('appliedRules') : null;
        $appliedRules = $method?->isPublic() === true ? $method->invoke($rule) : null;
        $configuration = is_array($appliedRules) ? $appliedRules : [];

        return [
            'min' => $this->integer($configuration['min'] ?? $this->property($rule, 'min', 8)),
            'max' => isset($configuration['max']) ? $this->integer($configuration['max']) : $this->nullableInt($this->property($rule, 'max')),
            'required' => (bool) $this->property($rule, 'required', false),
            'sometimes' => (bool) $this->property($rule, 'sometimes', false),
            'mixedCase' => (bool) ($configuration['mixedCase'] ?? $this->property($rule, 'mixedCase', false)),
            'letters' => (bool) ($configuration['letters'] ?? $this->property($rule, 'letters', false)),
            'numbers' => (bool) ($configuration['numbers'] ?? $this->property($rule, 'numbers', false)),
            'symbols' => (bool) ($configuration['symbols'] ?? $this->property($rule, 'symbols', false)),
            'uncompromised' => (bool) ($configuration['uncompromised'] ?? $this->property($rule, 'uncompromised', false)),
            'compromisedThreshold' => $this->integer($configuration['compromisedThreshold'] ?? $this->property($rule, 'compromisedThreshold', 0)),
            'customRules' => array_values((array) ($configuration['customRules'] ?? $this->property($rule, 'customRules', []))),
        ];
    }

    /** @param array<string, string> $messages */
    private function passwordChecks(Password $rule, string $path, string $field, array $messages): string
    {
        $configuration = $this->passwordConfiguration($rule);
        $checks = '';
        if ($configuration['mixedCase']) {
            $message = $this->message($messages, $field, 'password.mixed')
                ?? 'The '.$this->label($field).' field must contain at least one uppercase and one lowercase letter.';
            $checks .= '.regex(/(\\p{Ll}+.*\\p{Lu})|(\\p{Lu}+.*\\p{Ll})/u'.$this->checkError($message).')';
        }
        if ($configuration['letters']) {
            $message = $this->message($messages, $field, 'password.letters')
                ?? 'The '.$this->label($field).' field must contain at least one letter.';
            $checks .= '.regex(/\\p{L}/u'.$this->checkError($message).')';
        }
        if ($configuration['symbols']) {
            $message = $this->message($messages, $field, 'password.symbols')
                ?? 'The '.$this->label($field).' field must contain at least one symbol.';
            $checks .= '.regex(/\\p{Z}|\\p{S}|\\p{P}/u'.$this->checkError($message).')';
        }
        if ($configuration['numbers']) {
            $message = $this->message($messages, $field, 'password.numbers')
                ?? 'The '.$this->label($field).' field must contain at least one number.';
            $checks .= '.regex(/\\p{N}/u'.$this->checkError($message).')';
        }
        if ($configuration['uncompromised']) {
            $this->serverOnly[$path][] = 'password.uncompromised:'.$configuration['compromisedThreshold'];
        }

        return $checks;
    }

    private function property(object $object, string $property, mixed $default = null): mixed
    {
        $reflection = new \ReflectionClass($object);
        if (! $reflection->hasProperty($property)) {
            return $default;
        }

        return $reflection->getProperty($property)->getValue($object);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : $this->integer($value);
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value) && ! is_bool($value) && $value !== null) {
            throw new \UnexpectedValueException('Expected an integer-compatible password rule value.');
        }

        return (int) $value;
    }

    /** @return list<mixed> */
    private function rules(mixed $definition): array
    {
        if (is_string($definition)) {
            return explode('|', $definition);
        }

        return is_array($definition) ? array_values($definition) : [$definition];
    }

    /** @return array{string, ?string} */
    private function rule(mixed $rule): array
    {
        $parts = explode(':', $this->name($rule), 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function baseName(mixed $rule): string
    {
        return $this->rule($rule)[0];
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

    private function enumRule(Enum $rule, ?string $error): string
    {
        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('type');
        $enum = $property->getValue($rule);
        if (! is_string($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new \UnexpectedValueException('Laravel enum validation rules must reference a backed enum.');
        }
        $values = array_map(fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR), $enum::cases());

        return $this->schema('z.enum', ['['.implode(', ', $values).']'], $error);
    }

    /** @param list<string> $arguments */
    private function schema(string $function, array $arguments, ?string $error): string
    {
        if ($error !== null) {
            $arguments[] = '{ error: '.$error.' }';
        }

        return $function.'('.implode(', ', $arguments).')';
    }

    private function schemaError(?string $invalid, ?string $required): ?string
    {
        if ($required !== null) {
            return 'issue => issue.input === undefined ? '.json_encode($required, JSON_THROW_ON_ERROR).' : '.($invalid === null ? 'undefined' : json_encode($invalid, JSON_THROW_ON_ERROR));
        }

        return $invalid === null ? null : json_encode($invalid, JSON_THROW_ON_ERROR);
    }

    private function checkError(?string $message): string
    {
        return $message === null ? '' : ', '.$this->error(['error' => $message]);
    }

    /**
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $replacements
     */
    private function message(array $messages, string $field, string $rule, array $replacements = []): ?string
    {
        $message = $messages[$field.'.'.$rule] ?? $messages[$rule] ?? null;
        if (! is_string($message)) {
            return null;
        }

        return strtr($message, [
            ':attribute' => $this->label($field),
            ...$replacements,
        ]);
    }

    private function label(string $field): string
    {
        return str_replace(['_', '.'], ' ', $field);
    }

    /** @param array{error: string, path?: list<string>} $parameters */
    private function error(array $parameters): string
    {
        $parts = ['error: '.json_encode($parameters['error'], JSON_THROW_ON_ERROR)];
        if (isset($parameters['path'])) {
            $parts[] = 'path: '.json_encode($parameters['path'], JSON_THROW_ON_ERROR);
        }

        return '{ '.implode(', ', $parts).' }';
    }
}
