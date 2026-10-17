<?php

declare(strict_types=1);

namespace LaravelZero\Definition;

use Closure;
use LaravelZero\ContextField;
use LaravelZero\Query\ZQLBuilder;
use LaravelZero\ZeroContext;
use LogicException;

final class ZeroDefinition
{
    public bool $public = false;

    /** @var list<array{field: string, op: string, value: array{context: string}|array{literal: string|int|float|bool|null}}> */
    public array $authorization = [];

    /** @var list<string> */
    public array $authorizationRelations = [];

    /** @var array<string, string> */
    public array $serverFill = [];

    /** @var list<Closure(ZQLBuilder, ZeroContext): mixed> */
    public array $serverScopes = [];

    /** @var list<string> */
    public array $scopeRelations = [];

    /**
     * @var array<string, array{kind: string, field: string, operator?: string, members?: list<array<string, mixed>>, callback?: Closure, expression?: array<string, mixed>}>
     */
    public array $filters = [];

    /**
     * @var list<string>
     */
    public array $includes = [];

    /**
     * @var list<string>
     */
    public array $sorts = [];

    /**
     * @var list<array{string, 'asc'|'desc'}>
     */
    public array $defaultOrder = [];

    /**
     * @var array<string, class-string<\Illuminate\Foundation\Http\FormRequest>>
     */
    public array $requests = [];

    public int $defaultLimit = 50;

    public int $maxLimit = 1000;

    public static function make(): self
    {
        return new self;
    }

    public function publicReads(): self
    {
        $this->public = true;

        return $this;
    }

    public function authorize(string $field, string $operator, ContextField|string|int|float|bool|null $value): self
    {
        $this->authorization[] = ['field' => $field, 'op' => $operator, 'value' => $value instanceof ContextField ? ['context' => $value->value] : ['literal' => $value]];

        return $this;
    }

    public function authorizeRelation(string $relation): self
    {
        $this->authorizationRelations[] = $relation;

        return $this;
    }

    /** @param Closure(ZQLBuilder, ZeroContext): mixed $scope
     * @param  list<string>  $relations  Relationships needed by the scope but not necessarily exposed as includes.
     */
    public function enforceServerScope(Closure $scope, array $relations = []): self
    {
        $this->serverScopes[] = $scope;
        $this->relations(...$relations);

        return $this;
    }

    /** Declare schema dependencies without exposing them as client includes. */
    public function relations(string ...$relations): self
    {
        $this->scopeRelations = array_values(array_unique([...$this->scopeRelations, ...$relations]));

        return $this;
    }

    public function fillFromContext(string $field, ContextField|string $source): self
    {
        if (isset($this->serverFill[$field])) {
            throw new LogicException("Conflicting context fill for {$field}.");
        }

        $this->serverFill[$field] = $source instanceof ContextField ? $source->value : $source;

        return $this;
    }

    /**
     * @param  string|AllowedFilter|list<string|AllowedFilter>  ...$filters
     */
    public function allowedFilters(string|AllowedFilter|array ...$filters): self
    {
        $filters = count($filters) === 1 && is_array($filters[0]) ? $filters[0] : $filters;
        foreach ($filters as $filter) {
            $filter = is_string($filter) ? AllowedFilter::partial($filter) : $filter;
            if (! $filter instanceof AllowedFilter || isset($this->filters[$filter->name])) {
                throw new LogicException('Invalid or duplicate filter definition.');
            }

            $this->filters[$filter->name] = $filter->toArray();
        }

        return $this;
    }

    /**
     * @param  string|list<string>  ...$includes
     */
    public function allowedIncludes(string|array ...$includes): self
    {
        $includes = array_merge(...array_map(fn (string|array $value): array => is_array($value) ? $value : [$value], $includes));
        $this->includes = array_values(array_unique($includes));

        return $this;
    }

    /**
     * @param  string|list<string>  ...$sorts
     */
    public function allowedSorts(string|array ...$sorts): self
    {
        $sorts = array_merge(...array_map(fn (string|array $value): array => is_array($value) ? $value : [$value], $sorts));
        $this->sorts = array_values(array_unique($sorts));

        return $this;
    }

    public function defaultSort(string ...$sorts): self
    {
        $this->defaultOrder = array_map(fn (string $sort): array => [ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc'], array_values($sorts));

        return $this;
    }

    public function limits(int $default = 50, int $max = 1000): self
    {
        if ($default < 1 || $max < $default) {
            throw new LogicException('Query limits must be positive and default must not exceed max.');
        }

        $this->defaultLimit = $default;
        $this->maxLimit = $max;

        return $this;
    }

    /**
     * @param  array<string, class-string<\Illuminate\Foundation\Http\FormRequest>>  $requests
     */
    public function mutationRequests(array $requests): self
    {
        $this->requests = $requests;

        return $this;
    }
}
