<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use LaravelZero\Http\ContextResolver;
use LaravelZero\Tests\HttpTestCase;

uses(HttpTestCase::class);

test('context preserves numeric identifiers while protocol user id is a string', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->forceFill(['id' => 42]);
    $request = Request::create('/zero/query', 'POST');
    $request->setUserResolver(fn () => $user);

    $context = (new ContextResolver)($request);
    $this->assertSame(42, $context->get('user_id'));
    $this->assertSame('42', $context->userId());
});

test('cache requests are authenticated separately from end users', function (): void {
    /** @var HttpTestCase $this */
    $this->postJson('/zero/query', ['transform', []])->assertForbidden();
});

test('query endpoint uses authenticated identity instead of body context', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);
    $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', [['id' => 'q', 'name' => 'Task.all', 'args' => [(object) []]]]])
        ->assertOk()->assertJsonPath('userID', 'alice')->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'alice');
});

test('bad json returns protocol error without leaking exceptions', function (): void {
    /** @var HttpTestCase $this */
    $this->withHeader('X-Api-Key', 'private-test-secret')->postJson('/zero/query', ['other', []])
        ->assertOk()->assertJsonPath('kind', 'TransformFailed')->assertJsonPath('reason', 'parse');
});

test('generation command writes verified contracts and can detect drift', function (): void {
    /** @var HttpTestCase $this */
    $path = sys_get_temp_dir().'/laravel-zero-'.uniqid().'.ts';
    try {
        $this->artisan('zero:generate', ['--output' => $path])->assertSuccessful();
        $this->assertStringContainsString('export const Task =', file_get_contents($path));
        $this->artisan('zero:generate', ['--output' => $path, '--check' => true])->assertSuccessful();
        file_put_contents($path, '// out of date');
        $this->artisan('zero:generate', ['--output' => $path, '--check' => true])->assertFailed();
    } finally {
        @unlink($path);
    }
});
