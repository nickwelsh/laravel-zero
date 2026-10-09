<?php

namespace LaravelZero\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use LaravelZero\Http\ContextResolver;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroServiceProvider;

class HttpTest extends TestCase
{
    public function test_context_preserves_numeric_identifiers_while_protocol_user_id_is_a_string(): void
    {
        $user = (new User)->forceFill(['id' => 42]);
        $request = Request::create('/zero/query', 'POST');
        $request->setUserResolver(fn () => $user);
        $context = (new ContextResolver)($request);
        self::assertSame(42, $context->get('user_id'));
        self::assertSame('42', $context->userId());
    }

    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.models', $this->models());
        $app['config']->set('zero.api_key', 'private-test-secret');
        $app['config']->set('zero.middleware', []);
    }

    public function test_cache_requests_are_authenticated_separately_from_end_users(): void
    {
        $this->postJson('/zero/query', ['transform', []])->assertForbidden();
    }

    public function test_query_endpoint_uses_authenticated_identity_instead_of_body_context(): void
    {
        $user = (new User)->setKeyType('string');
        $user->forceFill(['id' => 'alice']);
        $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
            ->postJson('/zero/query', ['transform', [['id' => 'q', 'name' => 'Task.all', 'args' => [(object) []]]]])
            ->assertOk()->assertJsonPath('userID', 'alice')->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'alice');
    }

    public function test_bad_json_returns_protocol_error_without_leaking_exceptions(): void
    {
        $this->withHeader('X-Api-Key', 'private-test-secret')->postJson('/zero/query', ['other', []])
            ->assertOk()->assertJsonPath('kind', 'TransformFailed')->assertJsonPath('reason', 'parse');
    }

    public function test_generation_command_writes_verified_contracts_and_can_detect_drift(): void
    {
        $path = sys_get_temp_dir().'/laravel-zero-'.uniqid().'.ts';
        try {
            $this->artisan('zero:generate', ['--output' => $path])->assertSuccessful();
            self::assertStringContainsString('export const Task =', file_get_contents($path));
            $this->artisan('zero:generate', ['--output' => $path, '--check' => true])->assertSuccessful();
            file_put_contents($path, '// out of date');
            $this->artisan('zero:generate', ['--output' => $path, '--check' => true])->assertFailed();
        } finally {
            @unlink($path);
        }
    }
}
