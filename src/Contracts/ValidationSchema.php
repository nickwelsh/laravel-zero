<?php

namespace NickWelsh\LaravelZero\Contracts;

use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Validation\GeneratedValidationSchema;

interface ValidationSchema
{
    public function import(): string;

    public function argument(ArgumentShape $shape): ?string;

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $fieldSchemas
     */
    public function input(array $rules, string $inputClass, array $messages = [], array $fieldSchemas = []): GeneratedValidationSchema;

    public function filter(string $nodeType, string $validateFunction): string;
}
