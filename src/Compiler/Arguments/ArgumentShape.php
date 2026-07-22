<?php

namespace NickWelsh\LaravelZero\Compiler\Arguments;

use BackedEnum;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Inputs\ZeroInput;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final readonly class ArgumentShape
{
    /** @param list<ReflectionParameter> $parameters */
    public function __construct(public string $kind, public array $parameters) {}

    public static function from(ReflectionMethod $method): self
    {
        $parameters = array_values(array_slice($method->getParameters(), 1));
        if ($parameters === []) {
            return new self('none', []);
        }

        if (count($parameters) === 1 && is_subclass_of(self::typeName($parameters[0]), ZeroInput::class)) {
            return new self('input', $parameters);
        }

        return new self(count($parameters) === 1 ? 'scalar' : 'object', $parameters);
    }

    public function zod(): ?string
    {
        return match ($this->kind) {
            'none' => null,
            'input' => lcfirst(class_basename(self::typeName($this->parameters[0]))).'Schema',
            'scalar' => self::parameterZod($this->parameters[0]),
            'object' => 'z.object({'.implode(', ', array_map(
                fn (ReflectionParameter $parameter): string => $parameter->getName().': '.self::parameterZod($parameter),
                $this->parameters,
            )).'})',
            default => throw new ZeroCompilerException('ZERO-A100', "Unsupported argument shape [{$this->kind}]."),
        };
    }

    /** @param list<mixed> $wireArgs @return list<mixed> */
    public function hydrate(array $wireArgs): array
    {
        $value = $wireArgs[0] ?? null;

        return match ($this->kind) {
            'none' => [],
            'input' => [$this->hydrateInput($value)],
            'scalar' => [$this->coerce($this->parameters[0], $value)],
            'object' => $this->hydrateObject($value),
            default => [],
        };
    }

    private function hydrateInput(mixed $value): ZeroInput
    {
        if (! is_array($value)) {
            throw new ZeroCompilerException('ZERO-A101', 'Expected object arguments for Zero input.');
        }
        $class = self::typeName($this->parameters[0]);

        return $class::from($value);
    }

    /** @return list<mixed> */
    private function hydrateObject(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ZeroCompilerException('ZERO-A102', 'Expected object arguments.');
        }

        return array_map(function (ReflectionParameter $parameter) use ($value): mixed {
            if (! array_key_exists($parameter->getName(), $value)) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }
                throw new ZeroCompilerException('ZERO-A103', "Missing argument [{$parameter->getName()}].");
            }

            return $this->coerce($parameter, $value[$parameter->getName()]);
        }, $this->parameters);
    }

    private function coerce(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = self::typeName($parameter);
        if ($value === null && $parameter->allowsNull()) {
            return null;
        }
        if (is_subclass_of($type, BackedEnum::class)) {
            return $type::from($value);
        }
        $valid = match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
        if (! $valid) {
            throw new ZeroCompilerException('ZERO-A104', "Argument [{$parameter->getName()}] must be {$type}.");
        }

        return $type === 'float' ? (float) $value : $value;
    }

    private static function parameterZod(ReflectionParameter $parameter): string
    {
        $type = self::typeName($parameter);
        $zod = match ($type) {
            'string' => 'z.string()',
            'int' => 'z.number().int()',
            'float' => 'z.number()',
            'bool' => 'z.boolean()',
            'array' => 'z.array(z.unknown())',
            default => self::enumZod($type),
        };
        if ($parameter->allowsNull()) {
            $zod .= '.nullable()';
        }
        if ($parameter->isOptional()) {
            $zod .= '.optional()';
        }

        return $zod;
    }

    private static function enumZod(string $type): string
    {
        if (! enum_exists($type) || ! is_subclass_of($type, BackedEnum::class)) {
            throw new ZeroCompilerException('ZERO-A105', "Unsupported argument type [{$type}]. Use a scalar, backed enum, or ZeroInput.");
        }
        $values = array_map(fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR), $type::cases());

        return 'z.enum(['.implode(', ', $values).'])';
    }

    private static function typeName(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            throw new ZeroCompilerException('ZERO-A106', "Parameter [{$parameter->getName()}] needs one named type.");
        }

        return $type->getName();
    }
}
