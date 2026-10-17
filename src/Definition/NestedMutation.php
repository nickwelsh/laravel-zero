<?php

declare(strict_types=1);

namespace ZeroForLaravel\Definition;

use InvalidArgumentException;

final class NestedMutation
{
    public ?string $ability = null;

    public bool $usingParent = false;

    private function __construct(public readonly string $relation, public readonly string $operation) {}

    public static function create(string $relation): self
    {
        return new self($relation, 'create');
    }

    public static function update(string $relation): self
    {
        return new self($relation, 'update');
    }

    /** An additional ability on the parent policy, receiving the related model on updates. */
    public function authorize(string $ability): self
    {
        if ($ability === '') {
            throw new InvalidArgumentException('A relationship ability cannot be empty.');
        }

        $this->ability = $ability;

        return $this;
    }

    /** Inherit the immediate parent's authorization instead of checking the child policy. */
    public function authorizeUsingParent(): self
    {
        $this->usingParent = true;

        return $this;
    }
}
