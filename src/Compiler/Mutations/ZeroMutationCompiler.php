<?php

namespace NickWelsh\LaravelZero\Compiler\Mutations;

use BackedEnum;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\Operation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

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
            /** @var Expr\MethodCall $call */
            [$model, $serverOnly] = $this->zeroMutationRoot($call);
            $schema = $this->schemas->model($model);
            $verb = match ($call->name->toString()) {
                'create' => 'insert', default => $call->name->toString()
            };
            $array = $call->getArgs()[0]->value ?? null;
            if (! $array instanceof Expr\Array_) {
                throw $this->error($operation, $call, 'Mutation effect values must be an array literal.');
            }
            $parts = [];
            foreach ($array->items as $item) {
                if (! $item) {
                    continue;
                }
                if ($item->unpack) {
                    if ($item->value instanceof Expr\MethodCall && $item->value->name instanceof Node\Identifier && $item->value->name->toString() === 'validated') {
                        if ($shape->kind !== 'input') {
                            throw $this->error($operation, $item, 'validated() spread requires a ZeroInput argument.');
                        }
                        $inputClass = $shape->parameters[0]->getType()->getName();
                        $input = new $inputClass;
                        foreach (array_keys($input->rules()) as $field) {
                            if (str_contains($field, '.') || in_array($field, $serverOnly, true)) {
                                continue;
                            }
                            try {
                                $clientField = $schema->clientColumn($field);
                            } catch (\InvalidArgumentException) {
                                throw $this->error($operation, $item, "Validated input field [{$field}] is not a Zero column.", 'Mark it server-only or remove it from the model write.');
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
                if (in_array($serverColumn, $serverOnly, true)) {
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
        $code = file_get_contents($operation->method->getFileName()) ?: '';
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

    /** @return array{class-string, list<string>}|null */
    private function zeroMutationRoot(Expr\MethodCall $effect): ?array
    {
        $cursor = $effect->var;
        $serverOnly = [];
        while ($cursor instanceof Expr\MethodCall) {
            if ($cursor->name instanceof Node\Identifier && $cursor->name->toString() === 'serverOnly') {
                $argument = $cursor->getArgs()[0]->value ?? null;
                if ($argument instanceof Node\Scalar\String_) {
                    $serverOnly[] = $argument->value;
                } elseif ($argument instanceof Expr\Array_) {
                    foreach ($argument->items as $item) {
                        if ($item?->value instanceof Node\Scalar\String_) {
                            $serverOnly[] = $item->value->value;
                        }
                    }
                }
            }
            $cursor = $cursor->var;
        }
        if (! $cursor instanceof Expr\StaticCall || ! $cursor->name instanceof Node\Identifier || $cursor->name->toString() !== 'zeroMutate') {
            return null;
        }
        $class = $cursor->class;
        $model = $class instanceof Node\Name ? ($class->getAttribute('resolvedName')?->toString() ?? $class->toString()) : null;

        return $model && class_exists($model) ? [$model, $serverOnly] : null;
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
            return '['.implode(', ', array_map(fn (Node\ArrayItem $item): string => $this->expression($item->value, $operation, $shape), array_filter($expression->items))).']';
        }
        if ($expression instanceof Expr\ClassConstFetch && $expression->name instanceof Node\Identifier) {
            $class = $expression->class instanceof Node\Name ? ($expression->class->getAttribute('resolvedName')?->toString() ?? $expression->class->toString()) : null;
            if ($class && enum_exists($class)) {
                $case = constant($class.'::'.$expression->name->toString());
                if ($case instanceof BackedEnum) {
                    return json_encode($case->value, JSON_THROW_ON_ERROR);
                }
            }
        }

        throw $this->error($operation, $expression, 'Client mutation effect depends on a server-only value.', 'Pass it as mutation input or mark its field server-only.');
    }

    private function error(Operation $operation, ?Node $node, string $message, ?string $suggestion = null): ZeroCompilerException
    {
        return new ZeroCompilerException('ZERO-M102', $message, $operation->method->getFileName(), $operation->class, $operation->method->getName(), $node?->getStartLine() ?? $operation->method->getStartLine(), $suggestion);
    }
}
