<?php

declare(strict_types=1);

namespace LaravelZero\Definition;

use LogicException;

final class ZeroDefinition
{
    /**
     * @var array<string, array{kind: string, field: string}>
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

    /**
     * @param  list<string|AllowedFilter>  $filters
     */
    public function allowedFilters(array $filters): self
    {
        foreach ($filters as $filter) {
            $filter = is_string($filter) ? AllowedFilter::partial($filter) : $filter;
            if (! $filter instanceof AllowedFilter || isset($this->filters[$filter->name])) {
                throw new LogicException('Invalid or duplicate filter definition.');
            }

            $this->filters[$filter->name] = ['kind' => $filter->kind, 'field' => $filter->field];
        }

        return $this;
    }

    /**
     * @param  list<string>  $includes
     */
    public function allowedIncludes(array $includes): self
    {
        $this->includes = array_values(array_unique($includes));

        return $this;
    }

    /**
     * @param  list<string>  $sorts
     */
    public function allowedSorts(array $sorts): self
    {
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
