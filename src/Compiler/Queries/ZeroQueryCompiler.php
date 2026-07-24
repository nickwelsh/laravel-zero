<?php

namespace NickWelsh\LaravelZero\Compiler\Queries;

use BackedEnum;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Compiler\Filters\ZeroFilterCompiler;
use NickWelsh\LaravelZero\Contracts\ValidationSchema;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Inputs\ZeroFilterInput;
use NickWelsh\LaravelZero\Queries\ZeroQueryColumn;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;
use NickWelsh\LaravelZero\Validation\Zod;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionNamedType;
use ReflectionParameter;

final readonly class ZeroQueryCompiler
{
    private ValidationSchema $validation;

    public function __construct(private ZeroSchemaRegistry $schemas, ?ValidationSchema $validation = null)
    {
        $this->validation = $validation ?? new Zod;
    }

    public function compile(Operation $operation): string
    {
        [$method, $model, $calls] = $this->parse($operation);
        $schema = $this->schemas->model($model);
        $shape = ArgumentShape::from($operation->method);
        $expression = $this->renderCalls('zql.'.$schema->clientTable, $calls, $schema, $operation, $shape);

        $validator = $this->validation->argument($shape);
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
            $supported = ['where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereExists', 'applyFilter', 'orderBy', 'limit', 'one', 'related'];
            if (! in_array($name, $supported, true)) {
                throw $this->error($operation, $call, "Unsupported query operation [{$name}].", 'Use a documented V1 ZeroQueryBuilder operation.');
            }
            if (in_array($name, ['related', 'whereExists'], true)) {
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
                $expression .= ".{$name}({$rendered})";

                continue;
            }
            if ($name === 'applyFilter') {
                if (count($arguments) !== 2) {
                    throw $this->error($operation, $call, 'applyFilter expects a filter value and a filter definition class.');
                }
                $definition = $this->filterDefinitionClass($arguments[1]->value, $operation);
                $this->assertFilterInput($shape, $definition, $schema, $arguments[0]->value, $operation, $call);
                $filter = $this->expression($arguments[0]->value, $operation, $shape);
                $apply = ZeroFilterCompiler::applyName($definition);
                $expression .= ".where(filter => {$apply}(filter, {$filter}))";

                continue;
            }
            $rendered = [];
            foreach ($arguments as $index => $argument) {
                if ($index === 0 && in_array($name, ['where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'orderBy'], true)) {
                    $rendered[] = $this->columnExpression($argument->value, $schema, $operation, $shape);
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
            $rendered = $shape->kind === 'scalar' ? 'args' : 'args.'.$expression->name;
            $parameter = $this->parameter($shape, $expression->name);
            if ($parameter?->isDefaultValueAvailable()) {
                $rendered .= ' ?? '.$this->literal($parameter->getDefaultValue());
            }

            return $rendered;
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

    /** @return class-string<ZeroFilterDefinition> */
    private function filterDefinitionClass(Expr $expression, Operation $operation): string
    {
        if (! $expression instanceof Expr\ClassConstFetch || ! $expression->name instanceof Node\Identifier || strtolower($expression->name->toString()) !== 'class' || ! $expression->class instanceof Node\Name) {
            throw $this->error($operation, $expression, 'Filter definition must be a class literal.');
        }

        $definition = $this->resolvedName($expression->class);
        if (! class_exists($definition) || ! is_subclass_of($definition, ZeroFilterDefinition::class)) {
            throw $this->error($operation, $expression, "Filter definition [{$definition}] must extend ".ZeroFilterDefinition::class.'.');
        }

        return $definition;
    }

    /** @param class-string<ZeroFilterDefinition> $definition */
    private function assertFilterInput(ArgumentShape $shape, string $definition, ZeroModelSchema $querySchema, Expr $filter, Operation $operation, Node $node): void
    {
        if ($shape->kind !== 'input') {
            throw $this->error($operation, $node, 'applyFilter requires a ZeroFilterInput query argument.');
        }
        $parameter = $shape->parameters[0] ?? null;
        $type = $parameter?->getType();
        if (! $type instanceof ReflectionNamedType) {
            throw $this->error($operation, $node, 'applyFilter requires a ZeroFilterInput query argument.');
        }
        $input = $type->getName();
        if (! is_subclass_of($input, ZeroFilterInput::class)) {
            throw $this->error($operation, $node, 'applyFilter requires a ZeroFilterInput query argument.');
        }

        /** @var class-string<ZeroFilterInput> $input */
        $expected = $input::filterDefinition();
        if ($expected !== $definition) {
            throw $this->error($operation, $node, "Filter input [{$input}] is configured for [{$expected}], not [{$definition}].");
        }

        $filterField = $input::filterField();
        $parameterName = $parameter->getName();
        if (! $filter instanceof Expr\PropertyFetch || ! $filter->name instanceof Node\Identifier || $filter->name->toString() !== $filterField || ! $filter->var instanceof Expr\Variable || $filter->var->name !== $parameterName) {
            throw $this->error($operation, $filter, "applyFilter must use the validated [\${$parameterName}->{$filterField}] input property.");
        }

        $filterSchema = ZeroFilterDefinition::make($definition)->schema($this->schemas);
        if ($filterSchema->model !== $querySchema->modelClass) {
            throw $this->error($operation, $node, "Filter definition [{$definition}] targets model [{$filterSchema->model}], but this query targets [{$querySchema->modelClass}].");
        }
    }

    private function columnExpression(Expr $expression, ZeroModelSchema $schema, Operation $operation, ArgumentShape $shape): string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return json_encode($this->clientColumn($schema, $expression->value, $expression, $operation), JSON_THROW_ON_ERROR);
        }
        if ($expression instanceof Expr\ClassConstFetch && $expression->name instanceof Node\Identifier && $expression->class instanceof Node\Name) {
            $class = $this->resolvedName($expression->class);
            if (enum_exists($class) && is_subclass_of($class, ZeroQueryColumn::class)) {
                $case = constant($class.'::'.$expression->name->toString());
                if ($case instanceof ZeroQueryColumn && is_string($case->value)) {
                    return json_encode($this->clientColumn($schema, $case->value, $expression, $operation), JSON_THROW_ON_ERROR);
                }
            }
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            $parameter = $this->parameter($shape, $expression->name);
            $type = $parameter?->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), ZeroQueryColumn::class)) {
                /** @var class-string<ZeroQueryColumn> $columnEnum */
                $columnEnum = $type->getName();
                $columns = [];
                foreach ($columnEnum::cases() as $case) {
                    if (! is_string($case->value)) {
                        throw $this->error($operation, $expression, "Zero query column enum [{$columnEnum}] must be string-backed.");
                    }
                    $serverColumn = $case->value;
                    $clientColumn = $this->clientColumn($schema, $serverColumn, $expression, $operation);
                    $columns[] = json_encode($serverColumn, JSON_THROW_ON_ERROR).': '.json_encode($clientColumn, JSON_THROW_ON_ERROR);
                }

                return '({'.implode(', ', $columns).'} as const)['.$this->expression($expression, $operation, $shape).']';
            }
        }

        throw $this->error(
            $operation,
            $expression,
            'Dynamic query columns must use a string-backed enum implementing ZeroQueryColumn.',
            'Use a string literal or type the query parameter with a ZeroQueryColumn enum containing only allowed columns.',
        );
    }

    private function clientColumn(ZeroModelSchema $schema, string $column, Node $node, Operation $operation): string
    {
        try {
            return $schema->clientColumn($column);
        } catch (InvalidArgumentException $exception) {
            throw $this->error($operation, $node, $exception->getMessage());
        }
    }

    private function parameter(ArgumentShape $shape, string $name): ?ReflectionParameter
    {
        foreach ($shape->parameters as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }

    private function literal(mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return json_encode($value, JSON_THROW_ON_ERROR);
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
