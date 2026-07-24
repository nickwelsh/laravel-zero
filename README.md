# Laravel Zero

Laravel-first Zero queries and mutations. PHP is authoritative; generated TypeScript provides typed client queries, validation, and optimistic effects.

Pinned to `@rocicorp/zero` `1.8.0`. Experimental V1 API.

## Install

```bash
composer require nickwelsh/laravel-zero
php artisan vendor:publish --tag=zero
```

The `zero` tag publishes the package config and creates `app/Zero/ZeroContext.php` and `app/Zero/ContextResolver.php`. With the default React frontend config, it also generates `resources/js/zero/generated/provider.generated.tsx` and adds any missing Zero URL exports to `resources/js/globals.ts`. The provider is regenerated from package configuration; existing global declarations are never replaced.

You can publish only part of the setup when needed:

```bash
php artisan vendor:publish --tag=zero-config
php artisan vendor:publish --tag=zero-context
```

The generated provider accepts `userId` as a prop and includes a memoized context ready to customize for your application. Routes default to authenticated `POST /zero/query` and `POST /zero/mutate`.

## Configuration

Queries and mutators have independent discovery paths:

```php
'discovery' => [
    'queries' => [app_path('Zero/Queries')],
    'mutators' => [app_path('Zero/Mutators')],
],
```

`config/laravel-zero.php` also owns all Eloquent Zero model discovery, schema naming, connection, and publication settings. Laravel Zero mirrors those values into Eloquent Zero, so there is no second config file to publish or maintain.

The configured query and mutate routes are automatically excluded from CSRF validation. This follows `routes.prefix` and can be disabled by setting `routes.except_from_csrf` to `false`.

Frontend scaffolding defaults to React. Set `frontend.framework` to `null` to disable it. When `frontend.use_globals` is `false`, the generated provider reads `import.meta.env` directly instead of creating or updating `globals.ts`.

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

Models use `HasZero`. Supported runtime operations: `where`, `whereIn`, `whereNotIn`, null checks, `whereExists`, `applyFilter`, `orderBy`, `limit`, `one`, and direct relationships.

Dynamic filter and sort columns must be allowlisted with string-backed enums implementing `ZeroQueryColumn`. Define separate enums when the allowed filter and sort fields differ; do not include private or tenant-scoping columns unless callers should be able to select them:

```php
use NickWelsh\LaravelZero\Queries\ZeroOrderDirection;
use NickWelsh\LaravelZero\Queries\ZeroQueryColumn;

enum PartySort: string implements ZeroQueryColumn
{
    case DisplayName = 'display_name';
    case CreatedAt = 'created_at';
}

enum PartyFilter: string implements ZeroQueryColumn
{
    case DisplayName = 'display_name';
    case Status = 'status';
}

public function paginated(
    ZeroContext $context,
    int $limit,
    PartySort $orderBy = PartySort::DisplayName,
    ZeroOrderDirection $direction = ZeroOrderDirection::Asc,
): ZeroQueryBuilder {
    return Party::zeroQuery()
        ->where('user_id', $context->user_id)
        ->limit($limit)
        ->orderBy($orderBy, $direction);
}
```

The enum values use server-side column names. Generation verifies every case against the model schema, maps each case to its client-side column name, and emits a Zod enum. The same enum is hydrated before the PHP query runs, so values outside the allowlist are rejected on both client and server paths. Plain string literals remain supported for fixed columns; unrestricted dynamic string columns produce `ZERO-Q104`.

### Advanced filter groups

For data-grid filters with nested AND/OR groups, define a server-owned filter schema. Fields and relationships are explicit allowlists; protected columns never become filterable unless they are deliberately registered:

```php
use NickWelsh\LaravelZero\Filters\ZeroFilterBuilder;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Filters\ZeroFilterOperator;

final class PartyFilters extends ZeroFilterDefinition
{
    public function model(): string
    {
        return Party::class;
    }

    public function define(ZeroFilterBuilder $filter): void
    {
        $filter->string('name', 'display_name')
            ->label('Party name')
            ->operators(
                ZeroFilterOperator::Equals,
                ZeroFilterOperator::Contains,
            );

        $filter->enum('type', PartyType::class, 'party_type')
            ->label('Party type');

        $filter->relationship(
            'emails',
            'emailAddresses',
            EmailAddressFilters::class,
        )->label('Email addresses');
    }
}
```

`label()` is optional and defaults to a humanized field or relationship ID. Backed-enum fields automatically generate value/label options. Other finite fields can declare UI options explicitly:

```php
$filter->string('status')
    ->label('Status')
    ->values([
        'active' => 'Active',
        'archived' => 'Archived',
    ]);
```

Create one input containing the filter and any other grid arguments:

```php
use NickWelsh\LaravelZero\Inputs\ZeroFilterInput;

final class PartyGridInput extends ZeroFilterInput
{
    public static function filterDefinition(): string
    {
        return PartyFilters::class;
    }

    protected function additionalRules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

Apply it after immutable authorization constraints so user-controlled OR groups cannot escape their scope:

```php
public function grid(ZeroContext $context, PartyGridInput $input): ZeroQueryBuilder
{
    return Party::zeroQuery()
        ->where('user_id', $context->user_id)
        ->applyFilter($input->filter, PartyFilters::class)
        ->limit($input->limit);
}
```

The filter wire format has condition, group, and relationship nodes:

```json
{
  "type": "group",
  "combinator": "and",
  "children": [
    {"type": "condition", "field": "name", "operator": "contains", "value": "Acme"},
    {
      "type": "group",
      "combinator": "or",
      "children": [
        {"type": "condition", "field": "type", "operator": "equals", "value": "person"},
        {"type": "condition", "field": "type", "operator": "equals", "value": "company"}
      ]
    },
    {"type": "relationship", "relationship": "emails", "quantifier": "some"}
  ]
}
```

Generation exports the recursive Zod schema, node type, ZQL helper, and UI metadata through `inputs.generated.ts` and the public barrel. For `PartyFilters`, import `partyFilters` to access every definition's field IDs, labels, server/client columns, kinds, operators, values, relationships, and limits:

```ts
import {partyFilters} from '@/zero'

const root = partyFilters.definitions[partyFilters.rootDefinitionId]
const fields = root.fields
const relationships = root.relationships
```

Both Laravel and generated Zod reject unknown fields, relationships, operators, value types, excessive nesting, excessive nodes, oversized groups, and oversized `in` lists. Defaults are three group levels, three relationship levels, 50 total nodes, 20 children per group, 100 `in` values, and 1,000 characters per string. Override the protected limit properties on a definition when a screen needs stricter bounds.

Supported V1 relationship quantification is `some`, which compiles to ZQL `EXISTS`. Relationship counts remain unsupported by Zero, and `none` is intentionally omitted because Zero 1.8 cannot reliably execute `NOT EXISTS` against an incomplete client replica.

### Related filtering and ordering

Filter parent rows through a relationship with `whereExists`. The callback is scoped to the related model, so use an allowlist enum whose cases are columns on that model—no dot notation is needed:

```php
enum EmailAddressFilter: string implements ZeroQueryColumn
{
    case Address = 'address';
    case IsPrimary = 'is_primary';
}

return Party::zeroQuery()->whereExists(
    'emailAddresses',
    fn (ZeroQueryBuilder $emails) => $emails->where($field, $value),
);
```

`whereExists` callbacks can contain any supported filter operation and can nest another `whereExists` to traverse multiple relationships.

ZQL cannot order parent rows by a related row's column, so values such as `company.display_name` are not valid sort fields. If that ordering is required, store a sortable value on the parent table or introduce another denormalized sort key.

You can order the rows returned inside an included relationship:

```php
enum EmailAddressSort: string implements ZeroQueryColumn
{
    case Address = 'address';
    case CreatedAt = 'created_at';
}

return Party::zeroQuery()->related(
    'emailAddresses',
    fn (ZeroQueryBuilder $emails) => $emails->orderBy($orderBy, $direction),
);
```

This changes the order of `emailAddresses` within each party; it does not change the order of the parties themselves.

## Mutation

```php
#[ZeroMutationCollection('directory.party')]
final class PartyMutations implements ZeroMutations
{
    public function create(ZeroContext $context, CreatePartyInput $input)
    {
        return Party::zeroMutate()
            ->serverOnly('reference_code')
            ->ignore('password_confirmation')
            ->create([
                ...$input->validated(),
                'user_id' => $context->user_id,
                'reference_code' => app(ReferenceCodes::class)->next(),
            ]);
    }
}
```

`ZeroInput` rules run through Laravel. Portable rules become Zod 4 schemas, including object-level `confirmed` and `same` refinements. Database/service rules remain server-only and appear in the generated manifest. Override `messages()` on an input with Laravel-style `field.rule` keys to share custom validation messages between Laravel and generated Zod schemas:

```php
public function messages(): array
{
    return [
        'password.confirmed' => 'The passwords do not match.',
        'email.email' => 'Enter a valid email address.',
    ];
}
```

Laravel's fluent `Password` rule is resolved when schemas are generated. Minimum and maximum length plus `letters()`, `mixedCase()`, `numbers()`, and `symbols()` become Zod checks. `uncompromised()` and unsupported custom password rules remain server-only. Because `Password::defaults()` may depend on the application environment, run `php artisan zero:generate` in the target deployment environment after service providers have booted; do not reuse artifacts generated under different password defaults.

Use `serverOnly()` for values written only by the server, and `ignore()` for validated fields that should not be written by either client or server (such as password confirmations). Supported writes: create, update, upsert, delete, and sequential writes. Application writes and Zero's mutation metadata use the configured physical connection.

Generate and check:

```bash
php artisan zero:generate
php artisan zero:check
php artisan zero:clear
```

`zero:generate` optionally delegates schema generation to `eloquent-zero`, then writes deterministic files under `resources/js/zero/generated`, including `schema.generated.ts` and `provider.generated.tsx`. It regenerates the provider, adds only missing Zero URL globals, and writes `resources/js/zero/index.ts` as the public barrel. Every generated TypeScript file includes a do-not-edit banner. Type-only modules use `export type *` in the barrel.

Laravel Zero emits interfaces by default. Set `generation.declaration_style` to `type` to use type aliases for generated context and React provider declarations:

```php
'generation' => [
    'declaration_style' => 'type',
],
```

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
