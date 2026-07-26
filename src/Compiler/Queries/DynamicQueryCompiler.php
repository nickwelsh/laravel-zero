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
use NickWelsh\LaravelZero\Dynamic\DynamicModelMutation;
use NickWelsh\LaravelZero\Dynamic\DynamicModelQuery;
use NickWelsh\LaravelZero\Dynamic\DynamicMutationRegistry;
use NickWelsh\LaravelZero\Dynamic\DynamicQueryRegistry;

final readonly class DynamicQueryCompiler
{
    public function __construct(
        private DynamicQueryRegistry $queries,
        private DynamicMutationRegistry $mutations,
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
        foreach ($this->models() as [$modelClass, $query, $mutation]) {
            $imports[] = $this->schemaVariable($modelClass);
            $imports[] = 'type '.$this->parsedType($modelClass);
            $relations = [
                ...($query?->includes() ?? []),
                ...($mutation?->relationships() ?? []),
            ];
            foreach ($relations as $relation) {
                $imports[] = $this->schemaVariable($relation->getRelated()::class);
                $imports[] = 'type '.$this->parsedType($relation->getRelated()::class);
            }
        }

        sort($imports);

        return array_values(array_unique($imports));
    }

    public function runtime(): string
    {
        if ($this->queries->all() === [] && $this->mutations->all() === []) {
            return '';
        }

        $source = <<<'TS'
export type DynamicFilterOperator = '=' | '!=' | '<' | '>' | '<=' | '>=' | 'LIKE' | 'NOT LIKE' | 'ILIKE' | 'NOT ILIKE' | 'IN' | 'NOT IN' | 'IS' | 'IS NOT';
export type ZeroModelKey = Readonly<Record<string, string | number | boolean>>;
export type ZeroModelMetadata = {readonly model: string; readonly key: ZeroModelKey};
export type ZeroModelInstance<T> = T & {readonly __zero: ZeroModelMetadata};
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
  readonly modelName: string;
  readonly query?: (args: any) => unknown;
  readonly parser: RowParser<any>;
  readonly keyFields: readonly string[];
  readonly createdAt?: string;
  readonly updatedAt?: string;
  readonly mutations?: {
    readonly create?: (args: any) => unknown;
    readonly update?: (args: any) => unknown;
    readonly delete?: (args: any) => unknown;
    readonly relation?: (args: any) => unknown;
  };
  readonly includes: Readonly<Record<string, IncludeParser>>;
  readonly morphs: Readonly<Record<string, MorphParser>>;
};

export interface DynamicQueryRequest<TResult> {
  readonly request: unknown;
  parse(value: unknown): TResult;
}

type GenerateID = () => string | number;
type MutationExecutor = (request: unknown) => Promise<unknown>;
let executeMutation: MutationExecutor | undefined;
let generateID: GenerateID | undefined;

export function configureDefaultModelMutations(executor: MutationExecutor, generator?: GenerateID): void {
  executeMutation = executor;
  generateID = generator;
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
    if (!this.#config.query) {
      throw new Error(`Zero model [${this.#config.modelName}] does not define a dynamic query.`);
    }
    return this.#config.query(this.#args);
  }

  where(field: TField, value: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult>;
  where(field: TField, operator: DynamicFilterOperator, value: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult>;
  where(field: TField, operatorOrValue: DynamicFilterOperator | unknown, value?: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    const hasOperator = arguments.length === 3;
    const operator = hasOperator ? operatorOrValue as DynamicFilterOperator : '=';
    return this.next({filters: [...this.#args.filters, {field, operator, value: hasOperator ? value : operatorOrValue}]});
  }

  whereKey(value: unknown): DynamicQueryBuilder<TRow, TField, TIncludes, TSort, TResult> {
    if (this.#config.keyFields.length !== 1) {
      throw new Error(`whereKey() requires a single-column key for [${this.#config.modelName}].`);
    }
    return this.where(this.#config.keyFields[0] as TField, value);
  }

  with<K extends keyof TIncludes & string>(include: K): DynamicQueryBuilder<
    TRow & {[P in K]: TIncludes[P]},
    TField,
    TIncludes,
    TSort,
    TResult extends readonly unknown[] ? readonly (TRow & {[P in K]: TIncludes[P]})[] : TRow & {[P in K]: TIncludes[P]} | undefined
  >;
  with<K extends keyof TIncludes & string>(includes: readonly K[]): DynamicQueryBuilder<
    TRow & {[P in K]: TIncludes[P]},
    TField,
    TIncludes,
    TSort,
    TResult extends readonly unknown[] ? readonly (TRow & {[P in K]: TIncludes[P]})[] : TRow & {[P in K]: TIncludes[P]} | undefined
  >;
  with<K extends keyof TIncludes & string>(includeOrIncludes: K | readonly K[]): DynamicQueryBuilder<
    TRow & {[P in K]: TIncludes[P]},
    TField,
    TIncludes,
    TSort,
    TResult extends readonly unknown[] ? readonly (TRow & {[P in K]: TIncludes[P]})[] : TRow & {[P in K]: TIncludes[P]} | undefined
  > {
    const includes = Array.isArray(includeOrIncludes) ? includeOrIncludes : [includeOrIncludes];
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

  async create(values: Partial<Omit<TRow, keyof TIncludes>> & Partial<Record<keyof TIncludes, unknown>> & Record<string, unknown>): Promise<unknown> {
    const mutation = this.#config.mutations?.create;
    if (!mutation) throw new Error(`Create is not enabled for [${this.#config.modelName}].`);
    const writable: Record<string, unknown> = {...values};
    delete writable.__zero;
    if (this.#config.keyFields.length === 1 && writable[this.#config.keyFields[0]] === undefined) {
      if (!generateID) throw new Error('AppZeroProvider requires generateId when create values omit their primary key.');
      writable[this.#config.keyFields[0]] = generateID();
    }
    const now = Date.now();
    if (this.#config.createdAt && writable[this.#config.createdAt] === undefined) writable[this.#config.createdAt] = now;
    if (this.#config.updatedAt && writable[this.#config.updatedAt] === undefined) writable[this.#config.updatedAt] = now;
    const relations: Record<string, unknown> = {};
    for (const include of this.#args.includes) {
      if (include in writable) {
        relations[include] = writable[include];
        delete writable[include];
      }
    }
    return this.execute(mutation({values: writable, ...(Object.keys(relations).length === 0 ? {} : {relations})}));
  }

  async update(key: ZeroModelKey, values: Partial<TRow> & Record<string, unknown>): Promise<unknown> {
    const mutation = this.#config.mutations?.update;
    if (!mutation) throw new Error(`Update is not enabled for [${this.#config.modelName}].`);
    const writable: Record<string, unknown> = {...values};
    delete writable.__zero;
    if (this.#config.updatedAt) writable[this.#config.updatedAt] = Date.now();
    return this.execute(mutation({key, values: writable}));
  }

  async delete(key: ZeroModelKey): Promise<unknown> {
    const mutation = this.#config.mutations?.delete;
    if (!mutation) throw new Error(`Delete is not enabled for [${this.#config.modelName}].`);
    return this.execute(mutation({key}));
  }

  async mutateRelation(key: ZeroModelKey, relation: string, operation: string, args: Record<string, unknown>): Promise<unknown> {
    const mutation = this.#config.mutations?.relation;
    if (!mutation) throw new Error(`Relationship mutations are not enabled for [${this.#config.modelName}].`);
    return this.execute(mutation({key, relation, operation, ...args}));
  }

  key(value: unknown): ZeroModelKey {
    if (this.#config.keyFields.length === 1 && (typeof value !== 'object' || value === null || Array.isArray(value))) {
      return {[this.#config.keyFields[0]]: value} as ZeroModelKey;
    }
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      throw new Error(`Composite key for [${this.#config.modelName}] must be an object.`);
    }
    return Object.fromEntries(this.#config.keyFields.map(field => [field, (value as Record<string, unknown>)[field]])) as ZeroModelKey;
  }

  modelName(): string {
    return this.#config.modelName;
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
    const parsed = this.#config.parser.parse(row) as TRow & Record<string, unknown>;
    const key = Object.fromEntries(this.#config.keyFields.map(field => [field, parsed[field]])) as ZeroModelKey;
    return {...parsed, __zero: {model: this.#config.modelName, key}} as TRow;
  }

  private execute(request: unknown): Promise<unknown> {
    if (!executeMutation) {
      throw new Error('Default model mutations require an AppZeroProvider.');
    }
    return executeMutation(request);
  }
}

function createDynamicModel<
  TRow,
  TField extends string,
  TIncludes extends Record<string, unknown>,
  TSort extends string,
>(config: DynamicModelConfig<TRow>): DynamicQueryBuilder<ZeroModelInstance<TRow>, TField, TIncludes, TSort> {
  return new DynamicQueryBuilder<ZeroModelInstance<TRow>, TField, TIncludes, TSort>(config);
}
TS;

        foreach ($this->models() as [$modelClass, $query, $mutation]) {
            $source .= "\n\n".$this->modelExport($modelClass, $query, $mutation);
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

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function modelExport(string $modelClass, ?DynamicModelQuery $query, ?DynamicModelMutation $mutation): string
    {
        $class = class_basename($modelClass);
        $builder = $query?->definition();
        $includes = [...($query?->includes() ?? []), ...($mutation?->relationships() ?? [])];
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
                $related = MorphRelationship::related(new $modelClass, $name);
                $morphParsers[] = '    '.json_encode($name, JSON_THROW_ON_ERROR).": {parser: {$parser}, many: true, pivot: ".json_encode($pivot, JSON_THROW_ON_ERROR).', related: '.json_encode($related, JSON_THROW_ON_ERROR).'},';
            } else {
                $includeParsers[] = '    '.json_encode($name, JSON_THROW_ON_ERROR).": {parser: {$parser}, many: ".($many ? 'true' : 'false').'},';
            }
        }

        $filterType = $this->typescriptUnion(array_keys($builder?->dynamicFilters() ?? []));
        $sortType = $this->typescriptUnion($builder?->dynamicSorts() ?? []);
        $parsed = $this->parsedType($modelClass);
        $parser = $this->schemaVariable($modelClass);
        $schema = $this->schemas->model($modelClass);
        $model = new $modelClass;
        $keyFields = array_map($schema->clientColumn(...), $schema->primaryKey);
        $createdAtColumn = $model->getCreatedAtColumn();
        $updatedAtColumn = $model->getUpdatedAtColumn();
        $createdAt = $model->usesTimestamps() && is_string($createdAtColumn) && isset($schema->columns[$createdAtColumn])
            ? $schema->clientColumn($createdAtColumn)
            : null;
        $updatedAt = $model->usesTimestamps() && is_string($updatedAtColumn) && isset($schema->columns[$updatedAtColumn])
            ? $schema->clientColumn($updatedAtColumn)
            : null;
        $includeShape = $includeTypes === [] ? 'Record<never, never>' : "{\n".implode("\n", $includeTypes)."\n}";
        $queryLine = $query === null ? '' : '  query: queries.'.implode('.', explode('.', $query->name)).",\n";
        $mutationLines = [];
        if ($mutation !== null) {
            foreach ($mutation->operations() as $operation) {
                $mutationLines[] = "    {$operation}: mutations.{$mutation->name}.{$operation},";
            }
            if ($mutation->allows('update')) {
                $mutationLines[] = "    relation: mutations.{$mutation->name}.relation,";
            }
        }
        $mutationBlock = $mutationLines === [] ? '' : "  mutations: {\n".implode("\n", $mutationLines)."\n  },\n";
        $timestampLines = ($createdAt === null ? '' : '  createdAt: '.json_encode($createdAt, JSON_THROW_ON_ERROR).",\n")
            .($updatedAt === null ? '' : '  updatedAt: '.json_encode($updatedAt, JSON_THROW_ON_ERROR).",\n");

        $modelName = $mutation !== null ? $mutation->name : ($query !== null ? $query->name : $class);

        return "export type {$class}DynamicIncludes = {$includeShape};\n"
            ."export const {$class} = createDynamicModel<{$parsed}, {$filterType}, {$class}DynamicIncludes, {$sortType}>({\n"
            .'  modelName: '.json_encode($modelName, JSON_THROW_ON_ERROR).",\n"
            .$queryLine
            ."  parser: {$parser}.passthrough(),\n"
            .'  keyFields: '.json_encode($keyFields, JSON_THROW_ON_ERROR).",\n"
            .$timestampLines
            .$mutationBlock
            ."  includes: {\n".implode("\n", $includeParsers)."\n  },\n"
            ."  morphs: {\n".implode("\n", $morphParsers)."\n  },\n"
            .'});';
    }

    /**
     * @return list<array{class-string<Model>, DynamicModelQuery|null, DynamicModelMutation|null}>
     */
    private function models(): array
    {
        $models = [];
        $names = [];
        foreach ($this->queries->all() as $query) {
            $models[$query->modelClass()] = [$query->modelClass(), $query, null];
            $names[$query->name] = $query->modelClass();
        }
        foreach ($this->mutations->all() as $mutation) {
            $existingClass = $names[$mutation->name] ?? null;
            if ($existingClass !== null && $existingClass !== $mutation->modelClass()) {
                throw new \RuntimeException("Zero model name [{$mutation->name}] is shared by [{$existingClass}] and [{$mutation->modelClass()}].");
            }
            $existing = $models[$mutation->modelClass()] ?? [$mutation->modelClass(), null, null];
            $existing[2] = $mutation;
            $models[$mutation->modelClass()] = $existing;
        }
        ksort($models);

        return array_values($models);
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
