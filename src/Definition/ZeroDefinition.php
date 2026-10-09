<?php

namespace LaravelZero\Definition;

use LogicException;

final class ZeroDefinition
{
    public array $filters = [];

    public array $includes = [];

    public array $sorts = [];

    public array $defaultOrder = [];

    public array $requests = [];

    public int $defaultLimit = 50;

    public int $maxLimit = 1000;

    public static function make(): self
    {
        return new self;
    }

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

    public function allowedIncludes(array $includes): self
    {
        $this->includes = array_values(array_unique($includes));

        return $this;
    }

    public function allowedSorts(array $sorts): self
    {
        $this->sorts = array_values(array_unique($sorts));

        return $this;
    }

    public function defaultSort(string ...$sorts): self
    {
        $this->defaultOrder = array_map(fn ($sort) => [ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc'], $sorts);

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

    public function mutationRequests(array $requests): self
    {
        $this->requests = $requests;

        return $this;
    }
}
