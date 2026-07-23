<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Inputs\ZeroMutationInput;

final class CreatePartyInput extends ZeroMutationInput
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'display_name' => ['required', 'string', 'min:2'],
            'password_confirmation' => ['sometimes', 'same:display_name'],
            'reference_code' => ['sometimes', 'unique:parties,reference_code'],
        ];
    }
}
