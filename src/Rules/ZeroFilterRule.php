<?php

namespace NickWelsh\LaravelZero\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Filters\ZeroFilterValidator;

final readonly class ZeroFilterRule implements ValidationRule
{
    /** @param class-string<ZeroFilterDefinition> $definition */
    public function __construct(
        private ZeroFilterValidator $validator,
        private string $definition,
    ) {}

    /** @param Closure(string, string|null=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $this->validator->validate($value, $this->definition);
        } catch (InvalidArgumentException $exception) {
            $fail("The {$attribute} is invalid: {$exception->getMessage()}");
        }
    }
}
