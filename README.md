# Laravel Zero

Laravel-first Zero queries and mutations. PHP is authoritative; generated TypeScript provides typed client queries, validation, and optimistic effects.

Pinned to `@rocicorp/zero` `1.8.0`. Experimental V1 API.

## Install

```bash
composer require nickwelsh/laravel-zero
php artisan vendor:publish --tag=zero-config
```

Configure a readonly context and trusted request resolver:

```php
final readonly class ZeroContext
{
    public function __construct(public string $user_id) {}
}

final class ContextResolver implements \NickWelsh\LaravelZero\Context\ZeroContextResolver
{
    public function resolve(Request $request): object
    {
        return new ZeroContext((string) $request->user()->getAuthIdentifier());
    }
}
```

Set both classes in `config/laravel-zero.php`. Routes default to authenticated `POST /zero/query` and `POST /zero/mutate`.

## Query

```php
#[ZeroQueryCollection('directory.party')]
final class PartyQueries implements ZeroQueries
{
    public function byId(ZeroContext $context, string $id)
    {
        return Party::zeroQuery()
            ->where('user_id', $context->user_id)
            ->where('id', $id)
            ->one();
    }
}
```

Models use `HasZero`. Supported runtime operations: `where`, `whereIn`, `whereNotIn`, null checks, `orderBy`, `limit`, `one`, and direct relationships.

## Mutation

```php
#[ZeroMutationCollection('directory.party')]
final class PartyMutations implements ZeroMutations
{
    public function create(ZeroContext $context, CreatePartyInput $input)
    {
        return Party::zeroMutate()
            ->serverOnly('reference_code')
            ->create([
                ...$input->validated(),
                'user_id' => $context->user_id,
                'reference_code' => app(ReferenceCodes::class)->next(),
            ]);
    }
}
```

`ZeroInput` rules run through Laravel. Portable rules become Zod. Database/service rules remain server-only and appear in the generated manifest. Supported writes: create, update, upsert, delete, and sequential writes. Application writes and Zero's mutation metadata use the configured physical connection.

Generate and check:

```bash
php artisan zero:generate
php artisan zero:check
php artisan zero:clear
```

`zero:generate` optionally delegates schema generation to `eloquent-zero`, then writes deterministic files under `resources/js/zero/generated` without rewriting unchanged files.

## Portable PHP subset

Supported: arguments, context/input properties, scalar literals, arrays, backed enums, direct builder chains, validated-input spreads, and sequential mutation calls.

Rejected with coded diagnostics: arbitrary helper/service results in client effects, dynamic calls, Eloquent query analysis, loops, recursion, network/filesystem work, and general PHP transpilation. Server-only PHP remains unrestricted when generated effects do not depend on it.

## Development

```bash
composer test
bun install
bun run typecheck
composer run format
```

The TypeScript conformance fixture uses the exact pinned Zero package. PHP protocol fixtures cover query envelopes, ordered mutations, deduplication, rollback, application errors, and cleanup.
