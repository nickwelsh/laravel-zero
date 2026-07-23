<?php

namespace NickWelsh\LaravelZero\Compiler\Mutations;

use BackedEnum;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Inputs\ZeroInput;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionNamedType;

final readonly class ZeroMutationCompiler
{
    public function __construct(private ZeroSchemaRegistry $schemas) {}

    public function compile(Operation $operation): string
    {
        $method = $this->method($operation);
        $finder = new NodeFinder;
        $effectCalls = $finder->find($method->stmts ?? [], fn (Node $node): bool => $node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['create', 'update', 'upsert', 'delete'], true) && $this->zeroMutationRoot($node) !== null);
        usort($effectCalls, fn (Node $a, Node $b): int => $a->getStartFilePos() <=> $b->getStartFilePos());
        $shape = ArgumentShape::from($operation->method);
        $effects = [];

        foreach ($effectCalls as $call) {
            if (! $call instanceof Expr\MethodCall || ! $call->name instanceof Node\Identifier) {
                continue;
            }
            $root = $this->zeroMutationRoot($call);
            if ($root === null) {
                continue;
            }
            [$model, $serverOnly, $ignored] = $root;
            $schema = $this->schemas->model($model);
            $name = $call->name->toString();
            $verb = match ($name) {
                'create' => 'insert', default => $name
            };
            $array = $call->getArgs()[0]->value ?? null;
            if (! $array instanceof Expr\Array_) {
                throw $this->error($operation, $call, 'Mutation effect values must be an array literal.');
            }
            $parts = [];
            foreach ($array->items as $item) {
                if ($item->unpack) {
                    if ($item->value instanceof Expr\MethodCall && $item->value->name instanceof Node\Identifier && $item->value->name->toString() === 'validated') {
                        if ($shape->kind !== 'input') {
                            throw $this->error($operation, $item, 'validated() spread requires a ZeroInput argument.');
                        }
                        $inputType = $shape->parameters[0]->getType();
                        if (! $inputType instanceof ReflectionNamedType || ! is_subclass_of($inputType->getName(), ZeroInput::class)) {
                            throw $this->error($operation, $item, 'validated() spread requires a ZeroInput argument.');
                        }
                        $inputClass = $inputType->getName();
                        $input = new $inputClass;
                        foreach (array_keys($input->rules()) as $field) {
                            if (str_contains($field, '.') || in_array($field, [...$serverOnly, ...$ignored], true)) {
                                continue;
                            }
                            try {
                                $clientField = $schema->clientColumn($field);
                            } catch (\InvalidArgumentException) {
                                throw $this->error($operation, $item, "Validated input field [{$field}] is not a Zero column.", 'Mark it server-only, ignore it, or remove it from the model write.');
                            }
                            $parts[] = $clientField.': args.'.$field;
                        }

                        continue;
                    }
                    throw $this->error($operation, $item, 'Only $input->validated() may be spread into a mutation effect.');
                }
                if (! $item->key instanceof Node\Scalar\String_) {
                    throw $this->error($operation, $item, 'Mutation field names must be string literals.');
                }
                $serverColumn = $item->key->value;
                if (in_array($serverColumn, [...$serverOnly, ...$ignored], true)) {
                    continue;
                }
                $parts[] = $schema->clientColumn($serverColumn).': '.$this->expression($item->value, $operation, $shape);
            }
            $effects[] = 'await tx.mutate.'.$schema->clientTable.'.'.$verb.'({'.implode(', ', $parts).'});';
        }

        $validator = $shape->zod();
        $parameters = $shape->kind === 'none' ? '{tx, ctx}' : '{tx, ctx, args}';
        $body = $effects === [] ? '' : "\n    ".implode("\n    ", $effects)."\n  ";
        $callback = 'async ('.$parameters.") => {{$body}}";

        return "defineMutator(\n".($validator ? "  {$validator},\n" : '')."  {$callback},\n)";
    }

    private function method(Operation $operation): Stmt\ClassMethod
    {
        $filename = $operation->method->getFileName();
        $code = $filename === false ? '' : (file_get_contents($filename) ?: '');
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);
        $method = (new NodeFinder)->findFirst($ast, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->toString() === $operation->method->getName());
        if (! $method instanceof Stmt\ClassMethod) {
            throw $this->error($operation, null, 'Unable to parse mutation method.');
        }

        return $method;
    }

    /** @return array{class-string, list<string>, list<string>}|null */
    private function zeroMutationRoot(Expr\MethodCall $effect): ?array
    {
        $cursor = $effect->var;
        $serverOnly = [];
        $ignored = [];
        while ($cursor instanceof Expr\MethodCall) {
            $modifier = $cursor->name instanceof Node\Identifier ? $cursor->name->toString() : null;
            if (in_array($modifier, ['serverOnly', 'ignore'], true)) {
                $fields = $this->literalFields($cursor);
                if ($modifier === 'serverOnly') {
                    $serverOnly = [...$serverOnly, ...$fields];
                } else {
                    $ignored = [...$ignored, ...$fields];
                }
            }
            $cursor = $cursor->var;
        }
        if (! $cursor instanceof Expr\StaticCall || ! $cursor->name instanceof Node\Identifier || $cursor->name->toString() !== 'zeroMutate') {
            return null;
        }
        $class = $cursor->class;
        if (! $class instanceof Node\Name) {
            return null;
        }
        $model = $this->resolvedName($class);
        if (! class_exists($model)) {
            return null;
        }

        return [$model, array_values(array_unique($serverOnly)), array_values(array_unique($ignored))];
    }

    /** @return list<string> */
    private function literalFields(Expr\MethodCall $call): array
    {
        $argument = $call->getArgs()[0]->value ?? null;
        if ($argument instanceof Node\Scalar\String_) {
            return [$argument->value];
        }
        if (! $argument instanceof Expr\Array_) {
            return [];
        }

        $fields = [];
        foreach ($argument->items as $item) {
            if ($item->value instanceof Node\Scalar\String_) {
                $fields[] = $item->value->value;
            }
        }

        return $fields;
    }

    private function expression(Expr $expression, Operation $operation, ArgumentShape $shape): string
    {
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) {
            return json_encode($expression->value, JSON_THROW_ON_ERROR);
        }
        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => 'true', 'false' => 'false', 'null' => 'null', default => throw $this->error($operation, $expression, 'Unsupported constant.')
            };
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $shape->kind === 'scalar' ? 'args' : 'args.'.$expression->name;
        }
        if ($expression instanceof Expr\PropertyFetch && $expression->name instanceof Node\Identifier && $expression->var instanceof Expr\Variable) {
            return $expression->var->name === $operation->method->getParameters()[0]->getName() ? 'ctx.'.$expression->name->toString() : 'args.'.$expression->name->toString();
        }
        if ($expression instanceof Expr\Array_) {
            return '['.implode(', ', array_map(fn (Node\ArrayItem $item): string => $this->expression($item->value, $operation, $shape), $expression->items)).']';
        }
        if ($expression instanceof Expr\ClassConstFetch && $expression->name instanceof Node\Identifier && $expression->class instanceof Node\Name) {
            $class = $this->resolvedName($expression->class);
            if (enum_exists($class)) {
                $case = constant($class.'::'.$expression->name->toString());
                if ($case instanceof BackedEnum) {
                    return json_encode($case->value, JSON_THROW_ON_ERROR);
                }
            }
        }

        throw $this->error($operation, $expression, 'Client mutation effect depends on a server-only value.', 'Pass it as mutation input or mark its field server-only.');
    }

    private function resolvedName(Node\Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Node\Name ? $resolvedName->toString() : $name->toString();
    }

    private function error(Operation $operation, ?Node $node, string $message, ?string $suggestion = null): ZeroCompilerException
    {
        $sourceFile = $operation->method->getFileName();
        $sourceLine = $node?->getStartLine() ?? $operation->method->getStartLine();

        return new ZeroCompilerException('ZERO-M102', $message, $sourceFile === false ? null : $sourceFile, $operation->class, $operation->method->getName(), $sourceLine === false ? null : $sourceLine, $suggestion);
    }
}
