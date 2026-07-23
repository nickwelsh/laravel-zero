<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Context\ContextTypeCompiler;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Compiler\Inputs\ZodRuleCompiler;
use NickWelsh\LaravelZero\Compiler\TypeScript\ZeroTypeScriptGenerator;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Queries\ZeroOrderDirection;
use NickWelsh\LaravelZero\Schema\EloquentZeroSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\AllowedQueries;
use NickWelsh\LaravelZero\Tests\Fixtures\CreatePartyInput;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\PartySort;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;

it('imports the generated schema without its TypeScript extension', function (): void {
    $files = app(ZeroTypeScriptGenerator::class)->render()['files'];

    expect($files['context.generated.ts'])->toContain("from './schema.generated';")
        ->and($files['queries.generated.ts'])->toContain("from './schema.generated';");
});

it('omits empty generated modules and their barrel exports', function (): void {
    $filesystem = new Filesystem;
    $directory = sys_get_temp_dir().'/laravel-zero-'.uniqid();
    $output = $directory.'/generated';
    $barrel = $directory.'/index.ts';

    try {
        config()->set('laravel-zero.discovery.queries', []);
        config()->set('laravel-zero.discovery.mutators', []);
        config()->set('laravel-zero.generation.output_directory', $output);
        config()->set('laravel-zero.generation.barrel_path', $barrel);
        config()->set('laravel-zero.generation.schema_path', $output.'/schema.generated.ts');
        app()->forgetInstance(ZeroRegistry::class);

        $filesystem->ensureDirectoryExists($output);
        $filesystem->put($output.'/inputs.generated.ts', "// This file is generated. Do not edit directly.\n\nexport default undefined;\n");

        $generator = app(ZeroTypeScriptGenerator::class);
        $files = $generator->render()['files'];
        $generator->write();

        expect($files)->not->toHaveKey('inputs.generated.ts')
            ->and($files['queries.generated.ts'])->toBe(<<<'TS'
// This file is generated. Do not edit directly.

import {defineQueries} from '@rocicorp/zero';

export const queries = defineQueries({});
TS
                ."\n")
            ->and($files['mutations.generated.ts'])->toBe(<<<'TS'
// This file is generated. Do not edit directly.

import {defineMutators} from '@rocicorp/zero';

export const mutations = defineMutators({});
TS
                ."\n")
            ->and($filesystem->exists($output.'/inputs.generated.ts'))->toBeFalse()
            ->and($filesystem->get($barrel))
            ->toContain("export type * from './generated/context.generated';")
            ->not->toContain('inputs.generated');
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('infers object argument schemas and optional defaults', function (): void {
    $operation = app(ZeroRegistry::class)->query('directory.party.byIdWithArchived');
    $shape = ArgumentShape::from($operation->method);

    expect($shape->kind)->toBe('object')
        ->and($shape->zod())->toBe('z.object({id: z.string(), includeArchived: z.boolean().optional()})')
        ->and($shape->hydrate([['id' => 'p1']]))->toBe(['p1', false]);
});

it('hydrates allowlist enums and rejects values outside them', function (): void {
    $shape = ArgumentShape::from(new ReflectionMethod(AllowedQueries::class, 'paginated'));

    expect($shape->hydrate([['limit' => 10]]))->toBe([
        10,
        PartySort::DisplayName,
        ZeroOrderDirection::Asc,
    ]);

    expect(fn () => $shape->hydrate([['limit' => 10, 'orderBy' => 'user_id']]))
        ->toThrow(ZeroCompilerException::class, 'ZERO-A104');
});

it('generates readonly nullable context fields and Zero registration', function (): void {
    $source = (new ContextTypeCompiler)->compile(TestZeroContext::class);

    expect($source)
        ->toContain('export interface ZeroContext {', 'readonly user_id: string;', 'readonly tenant_id: string | null;', 'schema: Schema;', 'context: ZeroContext;')
        ->not->toContain('export type ZeroContext');
});

it('can emit context types as type aliases', function (): void {
    config()->set('laravel-zero.generation.declaration_style', 'type');

    $source = app(ZeroTypeScriptGenerator::class)->render()['files']['context.generated.ts'];

    expect($source)
        ->toContain('export type ZeroContext = {')
        ->not->toContain('export interface ZeroContext');
});

it('maps portable validation to Zod 4 and reports server-only rules', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'id' => ['required', 'ulid'],
        'email' => ['required', 'string', 'email', 'max:100'],
        'birthday' => ['required', 'date'],
        'age' => ['sometimes', 'integer', 'min:18'],
        'code' => ['sometimes', 'unique:parties,code'],
    ], 'Input');

    expect($source)->toContain(
        'id: z.ulid()',
        'email: z.email().max(100)',
        'birthday: z.coerce.date()',
        'age: z.number().int().gte(18).optional()',
        'code: z.any().optional()',
    )->not->toContain('z.json()', 'z.string().email()', 'z.string().ulid()')
        ->and($compiler->notices())->toBe(['Input.code' => ['unique:parties,code']]);
});

it('maps Laravel password rule constraints and keeps breach checks server-only', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'password' => [
            'required',
            Password::min(12)->max(64)->mixedCase()->letters()->numbers()->symbols()->uncompromised(3)->rules(['not_regex:/password/i']),
        ],
    ], 'Input', [
        'password.mixed' => 'Use both uppercase and lowercase letters.',
    ]);

    expect($source)->toContain(
        'password: z.string().min(12).max(64)',
        '.regex(/(\\p{Ll}+.*\\p{Lu})|(\\p{Lu}+.*\\p{Ll})/u, { error: "Use both uppercase and lowercase letters." })',
        '.regex(/\\p{L}/u',
        '.regex(/\\p{N}/u',
        '.regex(/\\p{Z}|\\p{S}|\\p{P}/u',
    )->and($compiler->notices())->toBe([
        'Input.password' => ['not_regex:/password/i', 'password.uncompromised:3'],
    ]);
});

it('resolves environment-dependent default password rules when schemas are generated', function (): void {
    $previous = Password::$defaultCallback;

    try {
        Password::defaults(fn () => Password::min(app()->environment('testing') ? 10 : 20)->numbers());

        $source = (new ZodRuleCompiler)->object([
            'password' => Password::required(),
        ], 'Input');

        expect($source)
            ->toContain('password: z.string().min(10)', '.regex(/\\p{N}/u')
            ->not->toContain('.optional()');
    } finally {
        Password::$defaultCallback = $previous;
    }
});

it('maps confirmed and same rules with Laravel custom messages', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'password' => ['required', 'string', 'confirmed'],
        'password_confirmation' => ['required', 'string'],
        'email' => ['required', 'string', 'email'],
        'email_confirmation' => ['sometimes', 'string', 'same:email'],
    ], 'Input', [
        'password.confirmed' => 'The passwords do not match.',
        'email.email' => 'Enter a valid email address.',
        'email_confirmation.same' => 'The email addresses do not match.',
    ]);

    expect($source)
        ->toContain('email: z.email({ error: "Enter a valid email address." })')
        ->toContain('.refine(data => data["password"] === data["password_confirmation"], { error: "The passwords do not match.", path: ["password_confirmation"] })')
        ->toContain('.refine(data => data["email_confirmation"] === undefined || data["email_confirmation"] === data["email"], { error: "The email addresses do not match.", path: ["email_confirmation"] })')
        ->and($compiler->notices())->toBe([]);
});

it('adds an optional confirmation field when confirmed has no explicit rules', function (): void {
    $source = (new ZodRuleCompiler)->object([
        'password' => ['required', 'string', 'confirmed'],
    ], 'Input');

    expect($source)->toContain('password_confirmation: z.any().optional()', 'path: ["password_confirmation"]');
});

it('uses custom input messages during Laravel validation', function (): void {
    expect(fn () => CreatePartyInput::from([
        'id' => 'p1',
        'display_name' => 'Party',
        'password_confirmation' => 'Different',
    ]))->toThrow(ValidationException::class, 'The confirmation does not match the display name.');
});

it('maps scalar and object nested arrays', function (): void {
    $compiler = new ZodRuleCompiler;
    $source = $compiler->object([
        'tags' => ['required', 'array'],
        'tags.*' => ['required', 'string'],
        'contacts' => ['required', 'array'],
        'contacts.*.email' => ['required', 'string', 'email'],
    ], 'Input');

    expect($source)->toContain('tags: z.array(z.string())', 'contacts: z.array(z.object({email: z.email()}))');
});

it('resolves model metadata through the eloquent-zero bridge', function (): void {
    $schema = (new EloquentZeroSchemaRegistry)->model(Party::class);

    expect($schema->serverTable)->toBe('parties')
        ->and($schema->clientTable)->toBe('parties')
        ->and($schema->clientColumn('user_id'))->toBe('userId')
        ->and($schema->primaryKey)->toBe(['id']);
});
