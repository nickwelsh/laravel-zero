<?php

namespace NickWelsh\LaravelZero\Inputs;

use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Filters\ZeroFilterValidator;
use NickWelsh\LaravelZero\Rules\ZeroFilterRule;

abstract class ZeroFilterInput extends ZeroInput
{
    /** @return class-string<ZeroFilterDefinition> */
    abstract public static function filterDefinition(): string;

    public static function filterField(): string
    {
        return 'filter';
    }

    /** @return array<string, mixed> */
    final public function rules(): array
    {
        /** @var ZeroFilterValidator $validator */
        $validator = app(ZeroFilterValidator::class);

        return [
            ...$this->additionalRules(),
            static::filterField() => [
                'required',
                'array',
                new ZeroFilterRule($validator, static::filterDefinition()),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalRules(): array
    {
        return [];
    }
}
