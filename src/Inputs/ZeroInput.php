<?php

namespace NickWelsh\LaravelZero\Inputs;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

abstract class ZeroInput
{
    /** @var array<string, mixed> */
    private array $validated = [];

    /** @param array<string, mixed> $raw */
    final public function __construct(protected readonly array $raw = []) {}

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @param array<string, mixed> $raw */
    public static function from(array $raw): static
    {
        $input = new static($raw);
        $input->validate();

        return $input;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        if ($this->validated === []) {
            $this->validate();
        }

        return $this->validated;
    }

    public function __get(string $name): mixed
    {
        return $this->validated()[$name] ?? null;
    }

    /** @throws ValidationException */
    private function validate(): void
    {
        /** @var Validator $validator */
        $validator = app('validator')->make($this->raw, $this->rules());
        $this->validated = $validator->validate();
    }
}
