<?php

namespace LaravelZero\Generation;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ReflectionMethod;
use Throwable;

final class RuleExtractor
{
    private const PORTABLE = ['required', 'nullable', 'sometimes', 'string', 'boolean', 'integer', 'numeric', 'min', 'max', 'in', 'date', 'uuid', 'ulid'];

    public function extract(array $requests, array $writable, array $columns, array $operations): array
    {
        $validation = [];
        $diagnostics = [];
        foreach ($requests as $operation => $class) {
            if (! in_array($operation, $operations, true) || ! is_subclass_of($class, FormRequest::class)) {
                throw new LogicException("{$class}: mutationRequests must map enabled operations to FormRequest classes.");
            }
            $request = new $class;
            $request->setContainer(app());
            try {
                $rules = app()->call([$request, 'rules']);
            } catch (Throwable $error) {
                throw new LogicException("{$class}: cannot extract rules without runtime request data. Use declarative rules for automatic CRUD.", previous: $error);
            }
            if (! is_array($rules)) {
                throw new LogicException("{$class}: rules() must return an array.");
            }
            foreach ($rules as $field => $ruleset) {
                if (! in_array($field, [...$writable, 'id'], true)) {
                    throw new LogicException("{$class}: {$field} is not an allowed automatic mutation input.");
                }
                $ruleset = is_string($ruleset) ? explode('|', $ruleset) : $ruleset;
                $portable = [];
                foreach ($ruleset as $rule) {
                    $name = is_string($rule) ? explode(':', $rule, 2)[0] : get_debug_type($rule);
                    if (in_array($name, self::PORTABLE, true) && ($name !== 'date' || ($columns[$field]['date'] ?? false))) {
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
