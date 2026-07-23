<?php

namespace NickWelsh\LaravelZero\Compiler\Queries;

use BackedEnum;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;
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
        $expression = $this->renderCalls('zql.'.$schema->clientTable, $calls, $schema, $operation, $shape);

        $validator = $shape->zod();
        $callback = '({ctx, args}) => '.$expression;
        if ($shape->kind === 'none') {
            $callback = '({ctx}) => '.$expression;
        }

        if ($validator === null) {
            return "defineQuery(\n  {$callback},\n)";
        }

        return "defineQuery(\n  {$validator},\n  {$callback},\n)";
    }

    /** @param list<Expr\MethodCall> $calls */
    private function renderCalls(string $expression, array $calls, ZeroModelSchema $schema, Operation $operation, ArgumentShape $shape): string
    {
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $arguments = $call->getArgs();
            $supported = ['where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'orderBy', 'limit', 'one', 'related'];
            if (! in_array($name, $supported, true)) {
                throw $this->error($operation, $call, "Unsupported query operation [{$name}].", 'Use a documented V1 ZeroQueryBuilder operation.');
            }
            if ($name === 'related') {
                $relationshipName = $arguments[0]->value ?? null;
                if (! $relationshipName instanceof Node\Scalar\String_) {
                    throw $this->error($operation, $call, 'Relationship name must be a string literal.');
                }
                $relationship = $schema->relationship($relationshipName->value);
                $rendered = json_encode($relationship->name, JSON_THROW_ON_ERROR);
                if (isset($arguments[1])) {
                    /** @var class-string $relatedModel */
                    $relatedModel = $relationship->relatedModel;
                    $rendered .= ', '.$this->relationshipCallback($arguments[1]->value, $relatedModel, $operation, $shape);
                }
                $expression .= ".related({$rendered})";

                continue;
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

        return $expression;
    }

    /** @param class-string $relatedModel */
    private function relationshipCallback(Expr $closure, string $relatedModel, Operation $operation, ArgumentShape $shape): string
    {
        if ($closure instanceof Expr\ArrowFunction) {
            $body = $closure->expr;
            $parameter = $closure->params[0]->var->name ?? null;
        } elseif ($closure instanceof Expr\Closure) {
            $return = (new NodeFinder)->findFirst($closure->stmts, fn (Node $node): bool => $node instanceof Stmt\Return_);
            $body = $return instanceof Stmt\Return_ ? $return->expr : null;
            $parameter = $closure->params[0]->var->name ?? null;
        } else {
            throw $this->error($operation, $closure, 'Relationship callback must be an inline closure.');
        }
        if (! $body instanceof Expr || ! is_string($parameter)) {
            throw $this->error($operation, $closure, 'Relationship callback must directly return its query chain.');
        }
        $calls = [];
        $cursor = $body;
        while ($cursor instanceof Expr\MethodCall) {
            array_unshift($calls, $cursor);
            $cursor = $cursor->var;
        }
        if (! $cursor instanceof Expr\Variable || $cursor->name !== $parameter) {
            throw $this->error($operation, $closure, 'Relationship callback must extend its query parameter.');
        }

        return $parameter.' => '.$this->renderCalls($parameter, $calls, $this->schemas->model($relatedModel), $operation, $shape);
    }

    /** @return array{Stmt\ClassMethod, class-string, list<Expr\MethodCall>} */
    private function parse(Operation $operation): array
    {
        $filename = $operation->method->getFileName();
        $code = $filename === false ? '' : (file_get_contents($filename) ?: '');
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);
        $finder = new NodeFinder;
        $method = $finder->findFirst($ast, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->toString() === $operation->method->getName());
        if (! $method instanceof Stmt\ClassMethod) {
            throw $this->error($operation, null, 'Unable to parse query method.');
        }
        $return = collect($method->stmts ?? [])->first(fn (Stmt $statement): bool => $statement instanceof Stmt\Return_);
        if (! $return instanceof Stmt\Return_ || ! $return->expr) {
            throw $this->error($operation, $method, 'Query method must contain a direct return statement.');
        }
        [$cursor, $calls] = $this->flattenChain($return->expr);
        if ($cursor instanceof Expr\Variable && is_string($cursor->name)) {
            return $this->parseAssignedQuery($operation, $method, $cursor->name);
        }
        if (! $this->isZeroQueryRoot($cursor)) {
            throw $this->error($operation, $return->expr, 'Query must return a zeroQuery() method chain.');
        }

        $model = $this->modelFromRoot($cursor, $operation);

        return [$method, $model, $calls];
    }

    /** @return array{Stmt\ClassMethod, class-string, list<Expr\MethodCall>} */
    private function parseAssignedQuery(Operation $operation, Stmt\ClassMethod $method, string $variable): array
    {
        $model = null;
        $calls = [];
        foreach ($method->stmts ?? [] as $statement) {
            if (! $statement instanceof Stmt\Expression) {
                continue;
            }
            $expression = $statement->expr;
            $value = $expression instanceof Expr\Assign && $expression->var instanceof Expr\Variable && $expression->var->name === $variable
                ? $expression->expr
                : $expression;
            [$root, $nextCalls] = $this->flattenChain($value);
            if ($this->isZeroQueryRoot($root)) {
                $model = $this->modelFromRoot($root, $operation);
                array_push($calls, ...$nextCalls);
            } elseif ($root instanceof Expr\Variable && $root->name === $variable) {
                array_push($calls, ...$nextCalls);
            }
        }
        if ($model === null) {
            throw $this->error($operation, $method, "Unable to find zeroQuery() assignment for \${$variable}.");
        }

        return [$method, $model, $calls];
    }

    /** @return array{Expr, list<Expr\MethodCall>} */
    private function flattenChain(Expr $expression): array
    {
        $calls = [];
        $cursor = $expression;
        while ($cursor instanceof Expr\MethodCall) {
            array_unshift($calls, $cursor);
            $cursor = $cursor->var;
        }

        return [$cursor, $calls];
    }

    /** @phpstan-assert-if-true Expr\StaticCall $expression */
    private function isZeroQueryRoot(Expr $expression): bool
    {
        return $expression instanceof Expr\StaticCall && $expression->name instanceof Node\Identifier && $expression->name->toString() === 'zeroQuery';
    }

    /** @return class-string */
    private function modelFromRoot(Expr\StaticCall $cursor, Operation $operation): string
    {
        $class = $cursor->class;
        if (! $class instanceof Node\Name) {
            throw $this->error($operation, $cursor, 'Unable to resolve model class for zeroQuery().');
        }
        $model = $this->resolvedName($class);
        if (! class_exists($model)) {
            throw $this->error($operation, $cursor, 'Unable to resolve model class for zeroQuery().');
        }

        return $model;
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

        throw $this->error($operation, $expression, 'Unsupported portable query expression.', 'Use an argument, context/input property, literal, array, or backed enum case.');
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

        return new ZeroCompilerException('ZERO-Q104', $message, $sourceFile === false ? null : $sourceFile, $operation->class, $operation->method->getName(), $sourceLine === false ? null : $sourceLine, $suggestion);
    }
}
