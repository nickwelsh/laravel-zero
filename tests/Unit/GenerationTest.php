<?php

use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Context\ContextTypeCompiler;
use NickWelsh\LaravelZero\Compiler\Inputs\ZodRuleCompiler;
use NickWelsh\LaravelZero\Compiler\TypeScript\ZeroTypeScriptGenerator;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Schema\EloquentZeroSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;

it('imports the generated schema without its TypeScript extension', function (): void {
    $files = app(ZeroTypeScriptGenerator::class)->render()['files'];

    expect($files['context.generated.ts'])->toContain("from './schema.generated';")
        ->and($files['queries.generated.ts'])->toContain("from './schema.generated';");
});

it('infers object argument schemas and optional defaults', function (): void {
    $operation = app(ZeroRegistry::class)->query('directory.party.byIdWithArchived');
    $shape = ArgumentShape::from($operation->method);

    expect($shape->kind)->toBe('object')
        ->and($shape->zod())->toBe('z.object({id: z.string(), includeArchived: z.boolean().optional()})')
        ->and($shape->hydrate([['id' => 'p1']]))->toBe(['p1', false]);
});

it('generates readonly nullable context fields and Zero registration', function (): void {
    $source = (new ContextTypeCompiler)->compile(TestZeroContext::class);

    expect($source)->toContain('readonly user_id: string;', 'readonly tenant_id: string | null;', 'schema: Schema;', 'context: ZeroContext;');
});

it('maps portable validation and reports server-only rules', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'email' => ['required', 'string', 'email', 'max:100'],
        'age' => ['sometimes', 'integer', 'min:18'],
        'code' => ['nullable', 'string', 'unique:parties,code'],
    ], 'Input');

    expect($source)->toContain('email: z.string().email().max(100)', 'age: z.number().int().gte(18).optional()', 'code: z.string().nullable().optional()')
        ->and($compiler->notices())->toBe(['Input.code' => ['unique:parties,code']]);
});

it('maps scalar and object nested arrays', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'tags' => ['required', 'array'],
        'tags.*' => ['required', 'string'],
        'contacts' => ['required', 'array'],
        'contacts.*.email' => ['required', 'string', 'email'],
    ], 'Input');

    expect($source)->toContain('tags: z.array(z.string())', 'contacts: z.array(z.object({email: z.string().email()}))');
});

it('resolves model metadata through the eloquent-zero bridge', function (): void {
    $schema = (new EloquentZeroSchemaRegistry)->model(Party::class);

    expect($schema->serverTable)->toBe('parties')
        ->and($schema->clientTable)->toBe('parties')
        ->and($schema->clientColumn('user_id'))->toBe('userId')
        ->and($schema->primaryKey)->toBe(['id']);
});
