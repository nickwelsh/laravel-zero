<?php

namespace NickWelsh\LaravelZero\Queries;

use BackedEnum;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;

final class ZeroQueryBuilder
{
    /** @var list<array<string, mixed>> */
    private array $conditions = [];

    /** @var list<array{0: string, 1: string}> */
    private array $ordering = [];

    /** @var list<array<string, mixed>> */
    private array $related = [];

    private ?int $limitValue = null;

    public function __construct(private readonly ZeroSchemaRegistry $registry, private readonly string $modelClass) {}

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $operator = func_num_args() === 2 ? '=' : strtoupper((string) $operatorOrValue);
        $value = func_num_args() === 2 ? $operatorOrValue : $value;
        $allowed = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'];

        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported Zero operator [{$operator}].");
        }

        if ($value === null) {
            $operator = $operator === '!=' ? 'IS NOT' : 'IS';
        }

        return $this->condition($column, $operator, $value);
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        return $this->condition($column, 'IN', $values);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->condition($column, 'NOT IN', $values);
    }

    public function whereNull(string $column): self
    {
        return $this->condition($column, 'IS', null);
    }

    public function whereNotNull(string $column): self
    {
        return $this->condition($column, 'IS NOT', null);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Unsupported Zero order direction [{$direction}].");
        }
        $this->ordering[] = [$this->schema()->clientColumn($column), $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Zero query limit must be positive.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function one(): self
    {
        $this->limitValue = 1;

        return $this;
    }

    public function related(string $name, ?callable $callback = null): self
    {
        $relationship = $this->schema()->relationship($name);
        $builder = new self($this->registry, $relationship->relatedModel);
        if ($callback !== null) {
            $callback($builder);
        }
        $this->related[] = [
            'correlation' => [
                'parentField' => array_map($this->schema()->clientColumn(...), $relationship->parentColumns),
                'childField' => array_map($builder->schema()->clientColumn(...), $relationship->childColumns),
            ],
            'subquery' => $builder->toAst(),
        ];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toAst(bool $serverNames = true): array
    {
        $schema = $this->schema();
        $mapColumn = fn (string $column): string => $serverNames ? array_search($column, $schema->columns, true) ?: $column : $column;
        $conditions = array_map(function (array $condition) use ($mapColumn): array {
            $condition['left']['name'] = $mapColumn($condition['left']['name']);

            return $condition;
        }, $this->conditions);
        $ast = ['table' => $serverNames ? $schema->serverTable : $schema->clientTable];

        if (count($conditions) === 1) {
            $ast['where'] = $conditions[0];
        } elseif ($conditions !== []) {
            $ast['where'] = ['type' => 'and', 'conditions' => $conditions];
        }
        if ($this->related !== []) {
            $ast['related'] = $this->related;
        }
        if ($this->limitValue !== null) {
            $ast['limit'] = $this->limitValue;
        }
        if ($this->ordering !== []) {
            $ast['orderBy'] = array_map(fn (array $part): array => [$mapColumn($part[0]), $part[1]], $this->ordering);
        }

        return $ast;
    }

    private function condition(string $column, string $operator, mixed $value): self
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        $this->conditions[] = [
            'type' => 'simple',
            'op' => $operator,
            'left' => ['type' => 'column', 'name' => $this->schema()->clientColumn($column)],
            'right' => ['type' => 'literal', 'value' => $value],
        ];

        return $this;
    }

    private function schema(): ZeroModelSchema
    {
        return $this->registry->model($this->modelClass);
    }
}
