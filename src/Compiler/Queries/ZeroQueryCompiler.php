<?php

namespace NickWelsh\LaravelZero\Compiler\Queries;

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

final readonly class ZeroQueryCompiler
{
    public function __construct(private ZeroSchemaRegistry $schemas) {}

    public function compile(Operation $operation): string
    {
        [$method, $model, $calls] = $this->parse($operation);
        $schema = $this->schemas->model($model);
        $shape = ArgumentShape::from($operation->method);
        $expression = 'zql.'.$schema->clientTable;

        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $arguments = $call->getArgs();
            $supported = ['where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'orderBy', 'limit', 'one'];
            if (! in_array($name, $supported, true)) {
                throw $this->error($operation, $call, "Unsupported query operation [{$name}].", 'Use a documented V1 ZeroQueryBuilder operation.');
            }
            $rendered = [];
            foreach ($arguments as $index => $argument) {
                if ($index === 0 && in_array($name, ['where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'orderBy'], true)) {
                    if (! $argument->value instanceof Node\Scalar\String_) {
                        throw $this->error($operation, $argument->value, 'Column and relationship names must be string literals.');
                    }
                    $rendered[] = json_encode($schema->clientColumn($argument->value->value), JSON_THROW_ON_ERROR);
                } else {
                    $rendered[] = $this->expression($argument->value, $operation, $shape);
                }
            }
            $tsName = match ($name) {
                'whereIn' => 'where',
                'whereNotIn' => 'where',
                'whereNull' => 'where',
                'whereNotNull' => 'where',
                default => $name,
            };
            if ($name === 'whereIn') {
                array_splice($rendered, 1, 0, ['"IN"']);
            }
            if ($name === 'whereNotIn') {
                array_splice($rendered, 1, 0, ['"NOT IN"']);
            }
            if ($name === 'whereNull') {
                $rendered = [$rendered[0], '"IS"', 'null'];
            }
            if ($name === 'whereNotNull') {
                $rendered = [$rendered[0], '"IS NOT"', 'null'];
            }
            $expression .= ".{$tsName}(".implode(', ', $rendered).')';
        }

        $validator = $shape->zod();
        $callback = '({ctx, args}) => '.$expression;
        if ($shape->kind === 'none') {
            $callback = '({ctx}) => '.$expression;
        }

        return 'defineQuery('.($validator ? $validator.', ' : '').$callback.')';
    }

    /** @return array{Stmt\ClassMethod, class-string, list<Expr\MethodCall>} */
    private function parse(Operation $operation): array
    {
        $code = file_get_contents($operation->method->getFileName());
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code ?: '') ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);
        $finder = new NodeFinder;
        $method = $finder->findFirst($ast, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->toString() === $operation->method->getName());
        if (! $method instanceof Stmt\ClassMethod) {
            throw $this->error($operation, null, 'Unable to parse query method.');
        }
        $return = $finder->findFirst($method->stmts ?? [], fn (Node $node): bool => $node instanceof Stmt\Return_);
        if (! $return instanceof Stmt\Return_ || ! $return->expr) {
            throw $this->error($operation, $method, 'Query method must contain a direct return statement.');
        }
        $calls = [];
        $cursor = $return->expr;
        while ($cursor instanceof Expr\MethodCall) {
            array_unshift($calls, $cursor);
            $cursor = $cursor->var;
        }
        if (! $cursor instanceof Expr\StaticCall || ! $cursor->name instanceof Node\Identifier || $cursor->name->toString() !== 'zeroQuery') {
            throw $this->error($operation, $return->expr, 'Query must return a zeroQuery() method chain.');
        }
        $class = $cursor->class;
        $model = $class instanceof Node\Name ? ($class->getAttribute('resolvedName')?->toString() ?? $class->toString()) : null;
        if (! $model || ! class_exists($model)) {
            throw $this->error($operation, $cursor, 'Unable to resolve model class for zeroQuery().');
        }

        return [$method, $model, $calls];
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

        throw $this->error($operation, $expression, 'Unsupported portable query expression.', 'Use an argument, context/input property, literal, array, or backed enum case.');
    }

    private function error(Operation $operation, ?Node $node, string $message, ?string $suggestion = null): ZeroCompilerException
    {
        return new ZeroCompilerException('ZERO-Q104', $message, $operation->method->getFileName(), $operation->class, $operation->method->getName(), $node?->getStartLine() ?? $operation->method->getStartLine(), $suggestion);
    }
}
