<?php

namespace NickWelsh\LaravelZero\Filters;

use InvalidArgumentException;

final class ZeroFilterRelationship
{
    /** @var class-string */
    public ?string $relatedModel = null;

    /**
     * @param  class-string<ZeroFilterDefinition>  $definition
     */
    public function __construct(
        public readonly string $id,
        public string $label,
        public readonly string $relationship,
        public readonly string $definition,
    ) {}

    public function label(string $label): self
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException("Filter relationship [{$this->id}] label cannot be empty.");
        }
        $this->label = $label;

        return $this;
    }

    /** @param class-string $relatedModel */
    public function resolveRelatedModel(string $relatedModel): void
    {
        $this->relatedModel = $relatedModel;
    }
}
