<?php

namespace NickWelsh\LaravelZero\Compiler\Context;

use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use ReflectionClass;
use ReflectionNamedType;

final class ContextTypeCompiler
{
    public function compile(string $class, string $schemaImport = './schema.generated', string $declarationStyle = 'interface'): string
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $fields = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (! $parameter->isPromoted() || ! $reflection->getProperty($parameter->getName())->isReadOnly()) {
                throw new ZeroCompilerException('ZERO-C100', "Context field [{$parameter->getName()}] must be constructor-promoted readonly.", $reflection->getFileName() ?: null, $class);
            }
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType) {
                throw new ZeroCompilerException('ZERO-C101', "Unsupported context type for [{$parameter->getName()}].");
            }
            $ts = match ($type->getName()) {
                'string' => 'string',
                'int', 'float' => 'number',
                'bool' => 'boolean',
                'array' => 'ReadonlyArray<string | number | boolean>',
                default => throw new ZeroCompilerException('ZERO-C102', "Unsupported context type [{$type->getName()}] for [{$parameter->getName()}]."),
            };
            if ($type->allowsNull()) {
                $ts .= ' | null';
            }
            $fields[] = "  readonly {$parameter->getName()}: {$ts};";
        }

        $context = match ($declarationStyle) {
            'interface' => "export interface ZeroContext {\n".implode("\n", $fields)."\n}",
            'type' => "export type ZeroContext = {\n".implode("\n", $fields)."\n};",
            default => throw new ZeroCompilerException('ZERO-C103', "Unsupported TypeScript declaration style [{$declarationStyle}]. Use [interface] or [type]."),
        };

        return "import type {Schema} from '{$schemaImport}';\n\n{$context}\n\ndeclare module '@rocicorp/zero' {\n  interface DefaultTypes {\n    context: ZeroContext;\n    schema: Schema;\n  }\n}\n";
    }
}
