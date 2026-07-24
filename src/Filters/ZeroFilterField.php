<?php

namespace NickWelsh\LaravelZero\Filters;

use BackedEnum;
use InvalidArgumentException;

final class ZeroFilterField
{
    /** @var list<ZeroFilterOperator> */
    public array $operators;

    /** @var list<array{value: bool|float|int|string, label: string}> */
    public array $values = [];

    /** @var class-string<BackedEnum>|null */
    public ?string $enumClass = null;

    public ?string $clientColumn = null;

    /**
     * @param  list<ZeroFilterOperator>  $operators
     */
    public function __construct(
        public readonly string $id,
        public string $label,
        public readonly string $column,
        public readonly ZeroFilterKind $kind,
        array $operators,
    ) {
        $this->operators = $operators;
    }

    public function label(string $label): self
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException("Filter field [{$this->id}] label cannot be empty.");
        }
        $this->label = $label;

        return $this;
    }

    public function operators(ZeroFilterOperator ...$operators): self
    {
        foreach ($operators as $operator) {
            if (! $this->supports($operator)) {
                throw new InvalidArgumentException(
                    "Filter field [{$this->id}] of kind [{$this->kind->value}] does not support operator [{$operator->value}].",
                );
            }
        }

        $this->operators = array_values(array_unique($operators, SORT_REGULAR));

        return $this;
    }

    public function nullable(): self
    {
        foreach ([ZeroFilterOperator::IsNull, ZeroFilterOperator::IsNotNull] as $operator) {
            if (! in_array($operator, $this->operators, true)) {
                $this->operators[] = $operator;
            }
        }

        return $this;
    }

    /** @param array<array-key, mixed> $values */
    public function values(array $values): self
    {
        $normalized = [];
        $isList = array_is_list($values);

        foreach ($values as $key => $value) {
            if ($value instanceof BackedEnum) {
                $this->assertOptionType($value->value);
                $normalized[] = [
                    'value' => $value->value,
                    'label' => self::humanize($value->name),
                ];

                continue;
            }

            if (is_array($value) && array_key_exists('value', $value) && array_key_exists('label', $value)) {
                $optionValue = $value['value'];
                $optionLabel = $value['label'];
                if ((! is_bool($optionValue) && ! is_float($optionValue) && ! is_int($optionValue) && ! is_string($optionValue)) || ! is_string($optionLabel) || trim($optionLabel) === '') {
                    throw new InvalidArgumentException("Filter field [{$this->id}] values require scalar values and non-empty string labels.");
                }
                $this->assertOptionType($optionValue);
                $normalized[] = ['value' => $optionValue, 'label' => $optionLabel];

                continue;
            }

            if (! $isList) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException("Filter field [{$this->id}] associative values must map values to string labels.");
                }
                $this->assertOptionType($key);
                $normalized[] = ['value' => $key, 'label' => $value];

                continue;
            }

            if (! is_bool($value) && ! is_float($value) && ! is_int($value) && ! is_string($value)) {
                throw new InvalidArgumentException("Filter field [{$this->id}] values must be scalars, backed enums, or value/label arrays.");
            }
            $this->assertOptionType($value);
            $normalized[] = ['value' => $value, 'label' => self::humanize((string) $value)];
        }

        $this->values = $normalized;

        return $this;
    }

    /** @internal */
    public function resolveClientColumn(string $clientColumn): void
    {
        $this->clientColumn = $clientColumn;
    }

    /** @internal */
    public static function humanize(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value) ?? $value;
        $value = str_replace(['_', '-'], ' ', $value);

        return ucwords(strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value)));
    }

    private function assertOptionType(bool|float|int|string $value): void
    {
        $valid = match ($this->kind) {
            ZeroFilterKind::String, ZeroFilterKind::Date => is_string($value),
            ZeroFilterKind::Number => is_int($value) || (is_float($value) && is_finite($value)),
            ZeroFilterKind::Boolean => is_bool($value),
            ZeroFilterKind::Enum => is_int($value) || is_string($value),
        };
        if (! $valid) {
            throw new InvalidArgumentException("Filter field [{$this->id}] option value [".get_debug_type($value)."] does not match kind [{$this->kind->value}].");
        }
    }

    private function supports(ZeroFilterOperator $operator): bool
    {
        if (in_array($operator, [ZeroFilterOperator::IsNull, ZeroFilterOperator::IsNotNull], true)) {
            return true;
        }

        return match ($this->kind) {
            ZeroFilterKind::String => in_array($operator, [
                ZeroFilterOperator::Equals,
                ZeroFilterOperator::NotEquals,
                ZeroFilterOperator::Contains,
                ZeroFilterOperator::NotContains,
                ZeroFilterOperator::StartsWith,
                ZeroFilterOperator::EndsWith,
                ZeroFilterOperator::In,
                ZeroFilterOperator::NotIn,
            ], true),
            ZeroFilterKind::Number, ZeroFilterKind::Date => in_array($operator, [
                ZeroFilterOperator::Equals,
                ZeroFilterOperator::NotEquals,
                ZeroFilterOperator::GreaterThan,
                ZeroFilterOperator::GreaterThanOrEqual,
                ZeroFilterOperator::LessThan,
                ZeroFilterOperator::LessThanOrEqual,
                ZeroFilterOperator::In,
                ZeroFilterOperator::NotIn,
            ], true),
            ZeroFilterKind::Boolean => in_array($operator, [
                ZeroFilterOperator::Equals,
                ZeroFilterOperator::NotEquals,
            ], true),
            ZeroFilterKind::Enum => in_array($operator, [
                ZeroFilterOperator::Equals,
                ZeroFilterOperator::NotEquals,
                ZeroFilterOperator::In,
                ZeroFilterOperator::NotIn,
            ], true),
        };
    }
}
