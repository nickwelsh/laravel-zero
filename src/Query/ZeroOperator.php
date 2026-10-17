<?php

declare(strict_types=1);

namespace LaravelZero\Query;

use Closure;
use LaravelZero\Metadata\ModelMetadata;
use LaravelZero\Support\Input;
use LogicException;

final readonly class ZeroOperator
{
    /** @param Closure(string, ?Closure): array<string, mixed> $related */
    public function __construct(private ModelMetadata $model, private Closure $related) {}

    /** @return array<string, mixed> */
    public function cmp(string $field, mixed $operator, mixed $value = null): array
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = $value === null ? 'IS' : '=';
        }

        if (! isset($this->model->columns[$field]) || ! in_array($operator, ['=', '!=', '<', '<=', '>', '>=', 'IS', 'IS NOT', 'LIKE', 'ILIKE'], true)) {
            throw new LogicException('Unsupported ZQL comparison.');
        }

        if (! $value instanceof FilterParameter && (! in_array($operator, ['IS', 'IS NOT'], true) || $value !== null)) {
            Input::value($value, $this->model->columns[$field], $field);
        }

        return ['type' => 'simple', 'op' => $operator, 'left' => ['type' => 'column', 'name' => $field], 'right' => $value instanceof FilterParameter ? ['type' => 'filterValue'] : ['type' => 'literal', 'value' => $value]];
    }

    /** @param Closure(ZQLBuilder): mixed|null $refine
     * @return array<string, mixed>
     */
    public function exists(string $relation, ?Closure $refine = null): array
    {
        return ['type' => 'correlatedSubquery', 'op' => 'EXISTS', 'related' => ($this->related)($relation, $refine)];
    }

    /** @param array<string, mixed> ...$conditions
     * @return array<string, mixed>
     */
    public function or(array ...$conditions): array
    {
        return $this->group('or', array_values($conditions));
    }

    /** @param array<string, mixed> ...$conditions
     * @return array<string, mixed>
     */
    public function and(array ...$conditions): array
    {
        return $this->group('and', array_values($conditions));
    }

    /** @param list<array<string, mixed>> $conditions
     * @return array<string, mixed>
     */
    private function group(string $type, array $conditions): array
    {
        if ($conditions === []) {
            throw new LogicException('ZQL groups must not be empty.');
        }

        return ['type' => $type, 'conditions' => $conditions];
    }
}
