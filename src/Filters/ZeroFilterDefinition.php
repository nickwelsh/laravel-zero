<?php

namespace NickWelsh\LaravelZero\Filters;

use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;

abstract class ZeroFilterDefinition
{
    protected int $maxDepth = 3;

    protected int $maxRelationshipDepth = 3;

    protected int $maxNodes = 50;

    protected int $maxChildren = 20;

    protected int $maxInValues = 100;

    protected int $maxStringLength = 1000;

    /** @return class-string */
    abstract public function model(): string;

    abstract public function define(ZeroFilterBuilder $filter): void;

    final public function schema(ZeroSchemaRegistry $registry): ZeroFilterSchema
    {
        $this->validateLimits();
        $model = $this->model();
        $modelSchema = $registry->model($model);
        $builder = new ZeroFilterBuilder;
        $this->define($builder);

        $fields = $builder->fields();
        foreach ($fields as $field) {
            $field->resolveClientColumn($modelSchema->clientColumn($field->column));
        }

        $relationships = $builder->relationships();
        foreach ($relationships as $relationship) {
            $relationshipSchema = $modelSchema->relationship($relationship->relationship);
            $definition = self::make($relationship->definition);
            $relatedModel = $definition->model();
            if ($relatedModel !== $relationshipSchema->relatedModel) {
                throw new InvalidArgumentException(
                    "Filter relationship [{$relationship->id}] targets model [{$relationshipSchema->relatedModel}], but definition [{$relationship->definition}] declares [{$relatedModel}].",
                );
            }
            $registry->model($relatedModel);
            $relationship->resolveRelatedModel($relatedModel);
        }

        return new ZeroFilterSchema(
            $model,
            $fields,
            $relationships,
            $this->maxDepth,
            $this->maxRelationshipDepth,
            $this->maxNodes,
            $this->maxChildren,
            $this->maxInValues,
            $this->maxStringLength,
        );
    }

    /** @param class-string<ZeroFilterDefinition> $definition */
    final public static function make(string $definition): ZeroFilterDefinition
    {
        if (! is_subclass_of($definition, self::class)) {
            throw new InvalidArgumentException("Filter definition [{$definition}] must extend ".self::class.'.');
        }

        return new $definition;
    }

    private function validateLimits(): void
    {
        $positive = [
            'maxDepth' => $this->maxDepth,
            'maxNodes' => $this->maxNodes,
            'maxChildren' => $this->maxChildren,
            'maxInValues' => $this->maxInValues,
            'maxStringLength' => $this->maxStringLength,
        ];
        foreach ($positive as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Filter definition limit [{$name}] must be at least 1.");
            }
        }
        if ($this->maxRelationshipDepth < 0) {
            throw new InvalidArgumentException('Filter definition limit [maxRelationshipDepth] cannot be negative.');
        }
    }
}
