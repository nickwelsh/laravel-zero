<?php

declare(strict_types=1);

use LaravelZero\Http\ContextResolver;

return [
    // Empty discovers models with Zero attributes or a zero() definition under app/Models.
    'models' => [],
    'output' => resource_path('js/zero/generated.ts'),
    // Optional React wrapper. Its editable sibling config holds public connection options.
    'react' => false,
    // Synchronize after successful, non-pretend PostgreSQL migration batches.
    'sync_publication_on_migrate' => false,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),
    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),
    'route_prefix' => 'zero',
    'middleware' => ['api', 'auth'],
    // Bind this class (or replace it) to add trusted tenant context.
    'context_resolver' => ContextResolver::class,
];
