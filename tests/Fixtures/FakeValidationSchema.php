<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Contracts\ValidationSchema;
use NickWelsh\LaravelZero\Validation\GeneratedValidationSchema;

final class FakeValidationSchema implements ValidationSchema
{
    public function import(): string
    {
        return "import {schema} from 'fake-validation';";
    }

    public function argument(ArgumentShape $shape): ?string
    {
        return $shape->kind === 'none' ? null : "schema.argument('{$shape->kind}')";
    }

    public function input(array $rules, string $inputClass, array $messages = [], array $fieldSchemas = []): GeneratedValidationSchema
    {
        return new GeneratedValidationSchema("schema.input('{$inputClass}')");
    }

    public function filter(string $nodeType, string $validateFunction): string
    {
        return "schema.filter<{$nodeType}>({$validateFunction})";
    }
}
