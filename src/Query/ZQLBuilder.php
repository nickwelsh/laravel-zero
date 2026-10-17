<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use LogicException;

final class ZQLBuilder
{
    /** @var list<array<string, mixed>> */
    private array $conditions = [];

    public function __construct(private readonly ZeroOperator $operator) {}

    /** @param string|Closure(ZeroOperator): array<string, mixed> $field */
    public function where(string|Closure $field, mixed $operator = null, mixed $value = null): self
    {
        $this->conditions[] = $field instanceof Closure ? $field($this->operator) : (func_num_args() === 2 ? $this->operator->cmp($field, $operator) : $this->operator->cmp($field, $operator, $value));

        return $this;
    }

    /** @param Closure(ZQLBuilder): mixed|null $refine */
    public function whereExists(string $relation, ?Closure $refine = null): self
    {
        $this->conditions[] = $this->operator->exists($relation, $refine);

        return $this;
    }

    /** @return array<string, mixed> */
    public function condition(): array
    {
        if ($this->conditions === []) {
            throw new LogicException('A server scope must add a ZQL condition.');
        }

        return count($this->conditions) === 1 ? $this->conditions[0] : $this->operator->and(...$this->conditions);
    }
}
