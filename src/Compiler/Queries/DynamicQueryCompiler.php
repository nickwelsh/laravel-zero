<?php

namespace NickWelsh\LaravelZero\Compiler\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\MorphRelationship;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Dynamic\DynamicModelQuery;
use NickWelsh\LaravelZero\Dynamic\DynamicQueryRegistry;

final readonly class DynamicQueryCompiler
{
    public function __construct(
        private DynamicQueryRegistry $queries,
        private ZeroSchemaRegistry $schemas,
    ) {}

    /** @return array<string, string> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->queries->all() as $query) {
            $definitions[$query->name] = $this->definition($query);
        }

        return $definitions;
    }

    /** @return list<string> */
    public function schemaImports(): array
    {
        $imports = [];
        foreach ($this->queries->all() as $query) {
            $imports[] = $this->schemaVariable($query->modelClass());
            $imports[] = 'type '.$this->parsedType($query->modelClass());
            foreach ($query->includes() as $relation) {
                $imports[] = $this->schemaVariable($relation->getRelated()::class);
                $imports[] = 'type '.$this->parsedType($relation->getRelated()::class);
            }
        }

        sort($imports);

        return array_values(array_unique($imports));
    }

    public function runtime(): string
    {
        if ($this->queries->all() === []) {
            return '';
        }

        $source = <<<'TS'
export type DynamicFilterOperator = '=' | '!=' | '<' | '>' | '<=' | '>=' | 'LIKE' | 'NOT LIKE' | 'ILIKE' | 'NOT ILIKE' | 'IN' | 'NOT IN' | 'IS' | 'IS NOT';
type DynamicArgs = {
  readonly filters: readonly {readonly field: string; readonly operator: DynamicFilterOperator; readonly value?: unknown}[];
  readonly includes: readonly string[];
  readonly orderBy: readonly (readonly [string, 'asc' | 'desc'])[];
  readonly limit?: number;
  readonly one?: boolean;
};
type RowParser<T> = {parse(value: unknown): T};
type IncludeParser = {readonly parser: RowParser<unknown>; readonly many: boolean};
type MorphParser = IncludeParser & {readonly pivot: string; readonly related: string};
type DynamicModelConfig<TRow> = {
  readonly query: (args: any) => unknown;
  readonly parser: RowParser<TRow>;
  readonly includes: Readonly<Record<string, IncludeParser>>;
  readonly morphs: Readonly<Record<string, MorphParser>>;
};

export interface DynamicQueryRequest<TResult> {
  readonly request: unknown;
  parse(value: unknown): TResult;
}

export class DynamicQueryBuilder<
  TRow,
  TField extends string,
  TIncludes extends Record<string, unknown>,
  TSort extends string,
  TResult = readonly TRow[],
> implements DynamicQueryRequest<TResult> {
  readonly #config: DynamicModelConfig<TRow>;
  readonly #args: DynamicArgs;

  constructor(config: DynamicModelConfig<TRow>, args?: Partial<DynamicArgs>) {
    this.#config = config;
    this.#args = {
      filters: args?.filters ?? [],
      includes: args?.includes ?? [],
      orderBy: args?.orderBy ?? [],
      ...(args?.limit === undefined ? {} : {limit: args.limit}),
      ...(args?.one === undefined ? {} : {one: args.one}),
    };
  }

  get request(): unknown {
    return this.#config.query(this.#args);
  }

  where(field: TField, value: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult>;
  where(field: TField, operator: DynamicFilterOperator, value: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult>;
  where(field: TField, operatorOrValue: DynamicFilterOperator | unknown, value?: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    const hasOperator = arguments.length === 3;
    const operator = hasOperator ? operatorOrValue as DynamicFilterOperator : '=';
    return this.next({filters: [...this.#args.filters, {field, operator, value: hasOperator ? value : operatorOrValue}]});
  }

  with<K extends keyof TIncludes & string>(includes: readonly K[]): DynamicQueryBuilder<
    TRow & {[P in K]: TIncludes[P]},
    TField,
    TIncludes,
    TSort,
    TResult extends readonly unknown[] ? readonly (TRow & {[P in K]: TIncludes[P]})[] : TRow & {[P in K]: TIncludes[P]} | undefined
  > {
    return this.next({includes: [...new Set([...this.#args.includes, ...includes])]}) as never;
  }

  orderBy(field: TSort, direction: 'asc' | 'desc' = 'asc'): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    return this.next({orderBy: [...this.#args.orderBy, [field, direction]]});
  }

  limit(limit: number): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    return this.next({limit});
  }

  one(): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TRow | undefined> {
    return this.next({limit: 1, one: true}) as never;
  }

  parse(value: unknown): TResult {
    if (value === undefined) return undefined as TResult;
    if (Array.isArray(value)) return value.map(row => this.parseRow(row)) as TResult;
    return this.parseRow(value) as unknown as TResult;
  }

  private next(args: Partial<DynamicArgs>): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    return new DynamicQueryBuilder(this.#config, {...this.#args, ...args});
  }

  private parseRow(value: unknown): TRow {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      return this.#config.parser.parse(value);
    }
    const row: Record<string, unknown> = {...value};
    for (const include of this.#args.includes) {
      const morph = this.#config.morphs[include];
      if (morph) {
        const pivots = Array.isArray(row[morph.pivot]) ? row[morph.pivot] as readonly Record<string, unknown>[] : [];
        row[include] = pivots.flatMap(pivot => {
          const related = pivot[morph.related];
          return related === undefined || related === null ? [] : [morph.parser.parse(related)];
        });
        delete row[morph.pivot];
        continue;
      }
      const descriptor = this.#config.includes[include];
      if (!descriptor || row[include] === undefined || row[include] === null) continue;
      row[include] = descriptor.many && Array.isArray(row[include])
        ? row[include].map(item => descriptor.parser.parse(item))
        : descriptor.parser.parse(row[include]);
    }
    return this.#config.parser.parse(row);
  }
}

function createDynamicModel<
  TRow,
  TField extends string,
  TIncludes extends Record<string, unknown>,
  TSort extends string,
>(config: DynamicModelConfig<TRow>): DynamicQueryBuilder<TRow, TField, TIncludes, TSort> {
  return new DynamicQueryBuilder<TRow, TField, TIncludes, TSort>(config);
}
TS;

        foreach ($this->queries->all() as $query) {
            $source .= "\n\n".$this->modelExport($query);
        }

        return $source;
    }

    private function definition(DynamicModelQuery $query): string
    {
        $builder = $query->definition();
        $schema = $this->schemas->model($query->modelClass());
        $filters = $builder->dynamicFilters();
        $includes = $query->includes();
        $sorts = $builder->dynamicSorts();
        $max = $this->maxLimit();
        $filterFields = $this->zodEnum(array_keys($filters));
        $includeNames = $this->zodEnum(array_keys($includes));
        $sortFields = $this->zodEnum($sorts);
        $operators = [];
        foreach ($filters as $filter) {
            $operators = [...$operators, ...$filter->operators];
        }
        $operators = array_values(array_unique($operators));
        $operatorSchema = $this->zodEnum($operators);
        $literalSchema = 'z.union([z.string(), z.number(), z.boolean(), z.null(), z.array(z.union([z.string(), z.number(), z.boolean()]))])';
        $lines = [
            'defineQuery(',
            "  z.object({filters: z.array(z.object({field: {$filterFields}, operator: {$operatorSchema}, value: {$literalSchema}.optional()}).strict()).default([]), includes: z.array({$includeNames}).default([]), orderBy: z.array(z.tuple([{$sortFields}, z.enum(['asc', 'desc'])])).default([]), limit: z.number().int().min(1).max({$max}).optional(), one: z.boolean().optional()}).strict(),",
            '  ({args}) => {',
            "    let query: any = zql.{$schema->clientTable};",
        ];
        if ($filters !== []) {
            $lines[] = '    for (const filter of args.filters) {';
            $lines[] = '      switch (filter.field) {';
            foreach ($filters as $server => $filter) {
                $client = $schema->clientColumn($server);
                $lines[] = '        case '.json_encode($server, JSON_THROW_ON_ERROR).': query = query.where('.json_encode($client, JSON_THROW_ON_ERROR).', filter.operator, filter.value); break;';
            }
            $lines[] = '      }';
            $lines[] = '    }';
        }
        if ($includes !== []) {
            $lines[] = '    for (const include of args.includes) {';
            $lines[] = '      switch (include) {';
            foreach ($includes as $name => $relation) {
                if ($relation instanceof MorphToMany) {
                    $pivot = MorphRelationship::pivot($name);
                    $related = MorphRelationship::related(new ($query->modelClass()), $name);
                    $typeColumn = $this->clientColumn($relation->getMorphType());
                    $morphClass = json_encode($relation->getMorphClass(), JSON_THROW_ON_ERROR);
                    $lines[] = '        case '.json_encode($name, JSON_THROW_ON_ERROR).": query = query.related('{$pivot}', (pivot: any) => pivot.where(".json_encode($typeColumn, JSON_THROW_ON_ERROR).", {$morphClass}).related('{$related}')); break;";
                } else {
                    $lines[] = '        case '.json_encode($name, JSON_THROW_ON_ERROR).': query = query.related('.json_encode($name, JSON_THROW_ON_ERROR).'); break;';
                }
            }
            $lines[] = '      }';
            $lines[] = '    }';
        }
        if ($sorts !== []) {
            $lines[] = '    for (const [field, direction] of args.orderBy) {';
            $lines[] = '      switch (field) {';
            foreach ($sorts as $server) {
                $client = $schema->clientColumn($server);
                $lines[] = '        case '.json_encode($server, JSON_THROW_ON_ERROR).': query = query.orderBy('.json_encode($client, JSON_THROW_ON_ERROR).', direction); break;';
            }
            $lines[] = '      }';
            $lines[] = '    }';
        }
        $lines[] = '    if (args.limit !== undefined) query = query.limit(args.limit);';
        $lines[] = '    return args.one ? query.one() : query;';
        $lines[] = '  },';
        $lines[] = ')';

        return implode("\n", $lines);
    }

    private function modelExport(DynamicModelQuery $query): string
    {
        $class = class_basename($query->modelClass());
        $builder = $query->definition();
        $includes = $query->includes();
        $includeTypes = [];
        $includeParsers = [];
        $morphParsers = [];

        foreach ($includes as $name => $relation) {
            $type = $this->parsedType($relation->getRelated()::class);
            $many = $this->relationIsMany($relation);
            $includeTypes[] = '  '.json_encode($name, JSON_THROW_ON_ERROR).': '.($many ? "readonly {$type}[]" : "{$type} | undefined").';';
            $parser = $this->schemaVariable($relation->getRelated()::class);
            if ($relation instanceof MorphToMany) {
                $pivot = MorphRelationship::pivot($name);
                $related = MorphRelationship::related(new ($query->modelClass()), $name);
                $morphParsers[] = '    '.json_encode($name, JSON_THROW_ON_ERROR).": {parser: {$parser}, many: true, pivot: ".json_encode($pivot, JSON_THROW_ON_ERROR).', related: '.json_encode($related, JSON_THROW_ON_ERROR).'},';
            } else {
                $includeParsers[] = '    '.json_encode($name, JSON_THROW_ON_ERROR).": {parser: {$parser}, many: ".($many ? 'true' : 'false').'},';
            }
        }

        $filterType = $this->typescriptUnion(array_keys($builder->dynamicFilters()));
        $sortType = $this->typescriptUnion($builder->dynamicSorts());
        $parsed = $this->parsedType($query->modelClass());
        $parser = $this->schemaVariable($query->modelClass());
        $path = implode('.', explode('.', $query->name));
        $includeShape = $includeTypes === [] ? 'Record<never, never>' : "{\n".implode("\n", $includeTypes)."\n}";

        return "export type {$class}DynamicIncludes = {$includeShape};\n"
            ."export const {$class} = createDynamicModel<{$parsed}, {$filterType}, {$class}DynamicIncludes, {$sortType}>({\n"
            ."  query: queries.{$path},\n"
            ."  parser: {$parser}.passthrough(),\n"
            ."  includes: {\n".implode("\n", $includeParsers)."\n  },\n"
            ."  morphs: {\n".implode("\n", $morphParsers)."\n  },\n"
            .'});';
    }

    /** @param list<string> $values */
    private function zodEnum(array $values): string
    {
        return $values === [] ? 'z.never()' : 'z.enum('.json_encode($values, JSON_THROW_ON_ERROR).')';
    }

    /** @param list<string> $values */
    private function typescriptUnion(array $values): string
    {
        return $values === [] ? 'never' : implode(' | ', array_map(fn (string $value): string => json_encode($value, JSON_THROW_ON_ERROR), $values));
    }

    /** @param class-string $model */
    private function schemaVariable(string $model): string
    {
        return $this->tableVariable($this->schemas->model($model)->clientTable).'Schema';
    }

    /** @param class-string $model */
    private function parsedType(string $model): string
    {
        return 'Parsed'.Str::studly($this->tableVariable($this->schemas->model($model)->clientTable));
    }

    private function tableVariable(string $table): string
    {
        $name = Str::singular($table);
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;
        $name = preg_replace('/^[^A-Za-z_]+/', '', $name) ?? $name;

        return $name === '' ? 'tableSchema' : $name;
    }

    private function clientColumn(string $column): string
    {
        $casing = config('laravel-zero.generation.column_name_casing');
        if ($casing instanceof Casing) {
            return $casing->transform($column);
        }

        return is_string($casing)
            ? (Casing::tryFrom($casing)?->transform($column) ?? $column)
            : $column;
    }

    private function maxLimit(): int
    {
        $limit = config('laravel-zero.dynamic_queries.max_limit', 100);
        if (! is_int($limit) || $limit < 1) {
            throw new \UnexpectedValueException('Dynamic Zero max_limit must be a positive integer.');
        }

        return $limit;
    }

    /** @param Relation<Model, Model, mixed> $relation */
    private function relationIsMany(Relation $relation): bool
    {
        return ! $relation instanceof BelongsTo && ! $relation instanceof HasOne;
    }
}
