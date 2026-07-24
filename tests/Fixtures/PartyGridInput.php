<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Inputs\ZeroFilterInput;

final class PartyGridInput extends ZeroFilterInput
{
    public static function filterDefinition(): string
    {
        return PartyFilters::class;
    }

    protected function additionalRules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
