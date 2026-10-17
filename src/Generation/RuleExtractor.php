<?php

declare(strict_types=1);

namespace LaravelZero\Generation;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ReflectionMethod;
use Throwable;

/** @phpstan-import-type Column from \LaravelZero\Metadata\ModelMetadata */
final class RuleExtractor
{
    private const array PORTABLE = ['required', 'nullable', 'sometimes', 'string', 'boolean', 'integer', 'numeric', 'min', 'max', 'in', 'date', 'uuid', 'ulid'];

    /**
     * @param  array<string, class-string<FormRequest>>  $requests
     * @param  list<string>  $writable
     * @param  array<string, Column>  $columns
     * @param  list<string>  $operations
     * @return array{array<string, array<string, list<string>>>, list<string>}
     */
    public function extract(array $requests, array $writable, array $columns, array $operations): array
    {
        $validation = [];
        $diagnostics = [];
        foreach ($requests as $operation => $class) {
            if (! in_array($operation, $operations, true) || ! is_subclass_of($class, FormRequest::class)) {
                throw new LogicException("{$class}: mutationRequests must map supported operations to FormRequest classes.");
            }

            $request = new $class;
            $request->setContainer(app());
            try {
                $callback = [$request, 'rules'];
                if (! is_callable($callback)) {
                    throw new LogicException('FormRequest must define a public rules method.');
                }

                $rules = app()->call($callback);
            } catch (Throwable $error) {
                throw new LogicException("{$class}: cannot extract rules without runtime request data. Use declarative rules for automatic CRUD.", previous: $error);
            }

            if (! is_array($rules)) {
                throw new LogicException("{$class}: rules() must return an array.");
            }

            foreach ($rules as $field => $ruleset) {
                $root = explode('.', $field)[0];
                if ($root !== $field && in_array($root, $writable, true) && (($columns[$root]['type'] ?? null) === 'json' || in_array($columns[$root]['cast'] ?? null, ['array', 'object'], true))) {
                    $diagnostics[] = "{$class}.{$field}: nested JSON validation runs on the server.";

                    continue;
                }

                if (! in_array($field, [...$writable, 'id'], true)) {
                    throw new LogicException("{$class}: {$field} is not an allowed automatic mutation input.");
                }

                $ruleset = is_string($ruleset) ? explode('|', $ruleset) : $ruleset;
                if (! is_array($ruleset)) {
                    $ruleset = [$ruleset];
                }

                $portable = [];
                foreach ($ruleset as $rule) {
                    $name = is_string($rule) ? explode(':', $rule, 2)[0] : get_debug_type($rule);
                    if (is_string($rule) && in_array($name, self::PORTABLE, true) && ($name !== 'date' || ($columns[$field]['date'] ?? false))) {
                        $portable[] = $rule;
                    } else {
                        $diagnostics[] = "{$class}.{$field}: {$name} is server-only validation.";
                    }
                }

                $validation[$operation][$field] = $portable;
            }

            foreach (['prepareForValidation', 'passedValidation', 'withValidator', 'after'] as $hook) {
                if (method_exists($class, $hook) && (new ReflectionMethod($class, $hook))->getDeclaringClass()->getName() !== FormRequest::class) {
                    $diagnostics[] = "{$class}: {$hook} runs on the server; optimistic values may be reconciled.";
                }
            }
        }

        return [$validation, $diagnostics];
    }
}
