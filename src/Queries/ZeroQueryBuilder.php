<?php

namespace NickWelsh\LaravelZero\Queries;

use BackedEnum;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;
use NickWelsh\LaravelZero\Schema\ZeroRelationshipSchema;
use Stringable;

/**
 * @phpstan-type ZeroCondition array{
 *     type: 'simple',
 *     op: string,
 *     left: array{type: 'column', name: string},
 *     right: array{type: 'literal', value: mixed}
 * }
 */
final class ZeroQueryBuilder
{
    /** @var list<ZeroCondition> */
    private array $conditions = [];

    /** @var list<array{0: string, 1: string}> */
    private array $ordering = [];

    /** @var list<array{relationship: ZeroRelationshipSchema, builder: self}> */
    private array $related = [];

    private ?int $limitValue = null;

    private ?string $alias = null;

    /** @param class-string $modelClass */
    public function __construct(private readonly ZeroSchemaRegistry $registry, private readonly string $modelClass) {}

    public function where(string|ZeroQueryColumn $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            if (! is_scalar($operatorOrValue) && $operatorOrValue !== null && ! $operatorOrValue instanceof Stringable) {
                throw new InvalidArgumentException('Unsupported Zero operator ['.get_debug_type($operatorOrValue).'].');
            }
            $operator = strtoupper((string) $operatorOrValue);
        }
        $allowed = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'];

        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported Zero operator [{$operator}].");
        }

        if ($value === null) {
            if (! in_array($operator, ['=', '!='], true)) {
                throw new InvalidArgumentException("Zero null comparisons only support [=] and [!=], got [{$operator}].");
            }
            $operator = $operator === '!=' ? 'IS NOT' : 'IS';
        }

        return $this->condition($column, $operator, $value);
    }

    /** @param list<mixed> $values */
    public function whereIn(string|ZeroQueryColumn $column, array $values): self
    {
        return $this->condition($column, 'IN', $values);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string|ZeroQueryColumn $column, array $values): self
    {
        return $this->condition($column, 'NOT IN', $values);
    }

    public function whereNull(string|ZeroQueryColumn $column): self
    {
        return $this->condition($column, 'IS', null);
    }

    public function whereNotNull(string|ZeroQueryColumn $column): self
    {
        return $this->condition($column, 'IS NOT', null);
    }

    public function orderBy(string|ZeroQueryColumn $column, string|ZeroOrderDirection $direction = 'asc'): self
    {
        $direction = $direction instanceof ZeroOrderDirection ? $direction->value : strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Unsupported Zero order direction [{$direction}].");
        }
        $this->ordering[] = [$this->schema()->clientColumn($this->columnName($column)), $direction];

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

    /** @param null|callable(self): mixed $callback */
    public function related(string $name, ?callable $callback = null): self
    {
        $relationship = $this->schema()->relationship($name);
        /** @var class-string $relatedModel */
        $relatedModel = $relationship->relatedModel;
        $builder = new self($this->registry, $relatedModel);
        $builder->alias = $relationship->name;
        if ($callback !== null) {
            $callback($builder);
        }
        $this->related[] = ['relationship' => $relationship, 'builder' => $builder];

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

        if ($this->alias !== null) {
            $ast['alias'] = $this->alias;
        }

        if (count($conditions) === 1) {
            $ast['where'] = $conditions[0];
        } elseif ($conditions !== []) {
            $ast['where'] = ['type' => 'and', 'conditions' => $conditions];
        }
        if ($this->related !== []) {
            $ast['related'] = array_map(function (array $related) use ($serverNames): array {
                $relationship = $related['relationship'];
                $builder = $related['builder'];

                /** @var non-empty-list<string> $parentColumns */
                $parentColumns = $relationship->parentColumns;
                /** @var non-empty-list<string> $childColumns */
                $childColumns = $relationship->childColumns;

                return [
                    'system' => 'client',
                    'correlation' => [
                        'parentField' => $serverNames ? $parentColumns : array_map($this->schema()->clientColumn(...), $parentColumns),
                        'childField' => $serverNames ? $childColumns : array_map($builder->schema()->clientColumn(...), $childColumns),
                    ],
                    'subquery' => $builder->toAst($serverNames),
                ];
            }, $this->related);
        }
        if ($this->limitValue !== null) {
            $ast['limit'] = $this->limitValue;
        }
        if ($this->ordering !== []) {
            $ast['orderBy'] = array_map(fn (array $part): array => [$mapColumn($part[0]), $part[1]], $this->ordering);
        }

        return $ast;
    }

    private function condition(string|ZeroQueryColumn $column, string $operator, mixed $value): self
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        if (is_array($value)) {
            $value = array_map(fn (mixed $item): mixed => $item instanceof BackedEnum ? $item->value : $item, $value);
        }
        $this->conditions[] = [
            'type' => 'simple',
            'op' => $operator,
            'left' => ['type' => 'column', 'name' => $this->schema()->clientColumn($this->columnName($column))],
            'right' => ['type' => 'literal', 'value' => $value],
        ];

        return $this;
    }

    private function columnName(string|ZeroQueryColumn $column): string
    {
        $column = $column instanceof ZeroQueryColumn ? $column->value : $column;
        if (! is_string($column)) {
            throw new InvalidArgumentException('Zero query column enums must be string-backed.');
        }

        return $column;
    }

    private function schema(): ZeroModelSchema
    {
        return $this->registry->model($this->modelClass);
    }
}
