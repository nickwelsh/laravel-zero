<?php

namespace NickWelsh\LaravelZero\Compiler\Mutations;

use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Dynamic\DynamicModelMutation;
use NickWelsh\LaravelZero\Dynamic\DynamicMutationRegistry;

final readonly class DynamicMutationCompiler
{
    public function __construct(
        private DynamicMutationRegistry $mutations,
        private ZeroSchemaRegistry $schemas,
    ) {}

    /** @return array<string, string> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->mutations->all() as $mutation) {
            foreach ($mutation->operations() as $operation) {
                $definitions[$mutation->name.'.'.$operation] = $this->definition($mutation, $operation);
            }
            if ($mutation->allows('update')) {
                $definitions[$mutation->name.'.relation'] = $this->definition($mutation, 'relation');
            }
        }

        return $definitions;
    }

    private function definition(DynamicModelMutation $mutation, string $operation): string
    {
        $table = $this->schemas->model($mutation->modelClass())->clientTable;

        return match ($operation) {
            'create' => "defineMutator(\n"
                ."  z.object({values: z.record(z.string(), z.json()), relations: z.record(z.string(), z.json()).optional()}).strict(),\n"
                ."  async ({tx, args}) => {\n"
                ."    await tx.mutate.{$table}.insert(args.values as never);\n"
                ."  },\n"
                .')',
            'update' => "defineMutator(\n"
                ."  z.object({key: z.record(z.string(), z.json()), values: z.record(z.string(), z.json())}).strict(),\n"
                ."  async ({tx, args}) => {\n"
                ."    await tx.mutate.{$table}.update({...args.key, ...args.values} as never);\n"
                ."  },\n"
                .')',
            'delete' => "defineMutator(\n"
                ."  z.object({key: z.record(z.string(), z.json())}).strict(),\n"
                ."  async ({tx, args}) => {\n"
                ."    await tx.mutate.{$table}.delete(args.key as never);\n"
                ."  },\n"
                .')',
            'relation' => "defineMutator(\n"
                ."  z.object({key: z.record(z.string(), z.json()), relation: z.string(), operation: z.enum(['attach', 'detach', 'sync', 'syncWithoutDetaching', 'syncWithPivotValues', 'toggle', 'updateExistingPivot']), ids: z.json().optional(), relatedId: z.json().optional(), pivot: z.record(z.string(), z.json()).optional()}).strict(),\n"
                ."  async () => {},\n"
                .')',
            default => throw new \LogicException("Unknown default Zero mutation operation [{$operation}]."),
        };
    }
}
