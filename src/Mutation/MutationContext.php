<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

final readonly class MutationContext
{
    public function __construct(
        public string $operation,
        public ?Model $parent = null,
        public ?string $relation = null,
    ) {}

    public static function from(Request $request): self
    {
        $context = $request->attributes->get(self::class);

        return $context instanceof self ? $context : throw new LogicException('No Zero mutation context is available.');
    }
}
