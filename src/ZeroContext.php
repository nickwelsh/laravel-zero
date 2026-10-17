<?php

declare(strict_types=1);

namespace ZeroForLaravel;

use Illuminate\Auth\Access\AuthorizationException;

final readonly class ZeroContext
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public array $values) {}

    public function get(string $field): string|int|float|bool
    {
        $value = $this->values[$field] ?? null;
        if (! is_scalar($value)) {
            throw new AuthorizationException("Missing trusted context: {$field}.");
        }

        return $value;
    }

    public function userId(): ?string
    {
        return isset($this->values['user_id']) ? (string) $this->get('user_id') : null;
    }
}
