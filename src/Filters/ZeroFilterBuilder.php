<?php

namespace NickWelsh\LaravelZero\Filters;

use BackedEnum;
use InvalidArgumentException;

final class ZeroFilterBuilder
{
    /** @var array<string, ZeroFilterField> */
    private array $fields = [];

    /** @var array<string, ZeroFilterRelationship> */
    private array $relationships = [];

    public function string(string $id, ?string $column = null): ZeroFilterField
    {
        return $this->field($id, $column, ZeroFilterKind::String, [
            ZeroFilterOperator::Equals,
            ZeroFilterOperator::NotEquals,
            ZeroFilterOperator::Contains,
            ZeroFilterOperator::NotContains,
            ZeroFilterOperator::StartsWith,
            ZeroFilterOperator::EndsWith,
            ZeroFilterOperator::In,
            ZeroFilterOperator::NotIn,
        ]);
    }

    public function number(string $id, ?string $column = null): ZeroFilterField
    {
        return $this->field($id, $column, ZeroFilterKind::Number, [
            ZeroFilterOperator::Equals,
            ZeroFilterOperator::NotEquals,
            ZeroFilterOperator::GreaterThan,
            ZeroFilterOperator::GreaterThanOrEqual,
            ZeroFilterOperator::LessThan,
            ZeroFilterOperator::LessThanOrEqual,
            ZeroFilterOperator::In,
            ZeroFilterOperator::NotIn,
        ]);
    }

    public function boolean(string $id, ?string $column = null): ZeroFilterField
    {
        return $this->field($id, $column, ZeroFilterKind::Boolean, [
            ZeroFilterOperator::Equals,
            ZeroFilterOperator::NotEquals,
        ]);
    }

    public function date(string $id, ?string $column = null): ZeroFilterField
    {
        return $this->field($id, $column, ZeroFilterKind::Date, [
            ZeroFilterOperator::Equals,
            ZeroFilterOperator::NotEquals,
            ZeroFilterOperator::GreaterThan,
            ZeroFilterOperator::GreaterThanOrEqual,
            ZeroFilterOperator::LessThan,
            ZeroFilterOperator::LessThanOrEqual,
            ZeroFilterOperator::In,
            ZeroFilterOperator::NotIn,
        ]);
    }

    /** @param class-string<BackedEnum> $enum */
    public function enum(string $id, string $enum, ?string $column = null): ZeroFilterField
    {
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("Filter enum [{$enum}] must be a backed enum.");
        }

        $field = $this->field($id, $column, ZeroFilterKind::Enum, [
            ZeroFilterOperator::Equals,
            ZeroFilterOperator::NotEquals,
            ZeroFilterOperator::In,
            ZeroFilterOperator::NotIn,
        ]);
        $field->enumClass = $enum;
        $field->values($enum::cases());

        return $field;
    }

    /**
     * Register a filterable relationship.
     *
     * The two-argument form uses the public ID as the server relationship name.
     *
     * @param  class-string<ZeroFilterDefinition>|string  $relationshipName
     * @param  class-string<ZeroFilterDefinition>|null  $definition
     */
    public function relationship(string $id, string $relationshipName, ?string $definition = null): ZeroFilterRelationship
    {
        if ($definition === null) {
            $definition = $relationshipName;
            $relationshipName = $id;
        }

        if (! is_subclass_of($definition, ZeroFilterDefinition::class)) {
            throw new InvalidArgumentException("Related filter definition [{$definition}] must extend ".ZeroFilterDefinition::class.'.');
        }
        $this->guardAvailableId($id);

        return $this->relationships[$id] = new ZeroFilterRelationship(
            $id,
            ZeroFilterField::humanize($id),
            $relationshipName,
            $definition,
        );
    }

    /** @return array<string, ZeroFilterField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, ZeroFilterRelationship> */
    public function relationships(): array
    {
        return $this->relationships;
    }

    /** @param list<ZeroFilterOperator> $operators */
    private function field(string $id, ?string $column, ZeroFilterKind $kind, array $operators): ZeroFilterField
    {
        $this->guardAvailableId($id);

        return $this->fields[$id] = new ZeroFilterField(
            $id,
            ZeroFilterField::humanize($id),
            $column ?? $id,
            $kind,
            $operators,
        );
    }

    private function guardAvailableId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Filter field and relationship IDs cannot be empty.');
        }
        if (isset($this->fields[$id]) || isset($this->relationships[$id])) {
            throw new InvalidArgumentException("Duplicate filter field or relationship ID [{$id}].");
        }
    }
}
