<?php

namespace NickWelsh\LaravelZero\Validation;

use BackedEnum;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Compiler\Inputs\ZodRuleCompiler;
use NickWelsh\LaravelZero\Contracts\ValidationSchema;
use ReflectionNamedType;
use ReflectionParameter;

final class Zod implements ValidationSchema
{
    public function import(): string
    {
        return "import {z} from 'zod';";
    }

    public function argument(ArgumentShape $shape): ?string
    {
        return match ($shape->kind) {
            'none' => null,
            'input' => lcfirst(class_basename($this->typeName($shape->parameters[0]))).'Schema',
            'scalar' => $this->parameter($shape->parameters[0]),
            'object' => 'z.object({'.implode(', ', array_map(
                fn (ReflectionParameter $parameter): string => $parameter->getName().': '.$this->parameter($parameter),
                $shape->parameters,
            )).'})',
            default => throw new ZeroCompilerException('ZERO-A100', "Unsupported argument shape [{$shape->kind}]."),
        };
    }

    public function input(array $rules, string $inputClass, array $messages = [], array $fieldSchemas = []): GeneratedValidationSchema
    {
        $compiler = new ZodRuleCompiler;

        return new GeneratedValidationSchema(
            $compiler->object($rules, $inputClass, $messages, $fieldSchemas),
            $compiler->notices(),
        );
    }

    public function filter(string $nodeType, string $validateFunction): string
    {
        return "z.custom<{$nodeType}>((value): value is {$nodeType} => true).superRefine(\n"
            ."  (value, context) => {$validateFunction}(\n"
            ."    value,\n"
            ."    (message, path) => context.addIssue({code: 'custom', message, path}),\n"
            ."  ),\n"
            .')';
    }

    private function parameter(ReflectionParameter $parameter): string
    {
        $type = $this->typeName($parameter);
        $schema = match ($type) {
            'string' => 'z.string()',
            'int' => 'z.number().int()',
            'float' => 'z.number()',
            'bool' => 'z.boolean()',
            'array' => 'z.array(z.unknown())',
            default => $this->enum($type),
        };
        if ($parameter->allowsNull()) {
            $schema .= '.nullable()';
        }
        if ($parameter->isOptional()) {
            $schema .= '.optional()';
        }

        return $schema;
    }

    private function enum(string $type): string
    {
        if (! enum_exists($type) || ! is_subclass_of($type, BackedEnum::class)) {
            throw new ZeroCompilerException('ZERO-A105', "Unsupported argument type [{$type}]. Use a scalar, backed enum, or ZeroInput.");
        }
        $values = array_map(fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR), $type::cases());

        return 'z.enum(['.implode(', ', $values).'])';
    }

    private function typeName(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            throw new ZeroCompilerException('ZERO-A106', "Parameter [{$parameter->getName()}] needs one named type.");
        }

        return $type->getName();
    }
}
