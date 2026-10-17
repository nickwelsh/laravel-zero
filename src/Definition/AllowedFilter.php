<?php

declare(strict_types=1);

namespace ZeroForLaravel\Definition;

use Closure;
use LogicException;
use ZeroForLaravel\Enums\FilterOperator;

final readonly class AllowedFilter
{
    /** @param list<AllowedFilter> $members */
    private function __construct(public string $name, public string $kind, public string $field, public ?string $operator = null, public array $members = [], public ?Closure $callback = null) {}

    /** Callback builds a portable ZQL predicate. Use a model column as $field for a typed input, or boolean by default. */
    public static function callback(string $name, Closure $callback, ?string $field = null): self
    {
        return new self($name, 'callback', $field ?? '', callback: $callback);
    }

    /** @param list<AllowedFilter> $members */
    public static function groupOr(string $name, array $members): self
    {
        return self::group($name, $members, 'or');
    }

    /** @param list<AllowedFilter> $members */
    public static function groupAnd(string $name, array $members): self
    {
        return self::group($name, $members, 'and');
    }

    public static function operator(string $name, FilterOperator $operator, ?string $field = null): self
    {
        return new self($name, 'operator', $field ?? $name, $operator->value);
    }

    public static function beginsWithStrict(string $name, ?string $field = null): self
    {
        return new self($name, 'beginsWith', $field ?? $name);
    }

    public static function endsWithStrict(string $name, ?string $field = null): self
    {
        return new self($name, 'endsWith', $field ?? $name);
    }

    public static function exact(string $name, ?string $field = null): self
    {
        return new self($name, 'exact', $field ?? $name);
    }

    public static function partial(string $name, ?string $field = null): self
    {
        return new self($name, 'partial', $field ?? $name);
    }

    public static function belongsTo(string $relation): self
    {
        return new self($relation, 'belongsTo', $relation);
    }

    public static function trashed(): self
    {
        return new self('trashed', 'trashed', '');
    }

    /** @return array{kind: string, field: string, operator?: string, members?: list<array<string, mixed>>, callback?: Closure, expression?: array<string, mixed>} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'field' => $this->field, ...($this->operator !== null ? ['operator' => $this->operator] : []), ...($this->members !== [] ? ['members' => array_map(fn (self $member): array => $member->toArray(), $this->members)] : []), ...($this->callback instanceof Closure ? ['callback' => $this->callback] : [])];
    }

    /** @param list<AllowedFilter> $members */
    private static function group(string $name, array $members, string $operator): self
    {
        if ($members === []) {
            throw new LogicException('Filter groups must not be empty.');
        }

        foreach ($members as $member) {
            if (! in_array($member->kind, ['exact', 'partial', 'beginsWith', 'endsWith', 'operator'], true)) {
                throw new LogicException('Filter groups require scalar member filters.');
            }
        }

        return new self($name, 'group', $members[0]->field, $operator, $members);
    }
}
