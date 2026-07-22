<?php

use NickWelsh\LaravelZero\Context\NullZeroContextResolver;
use NickWelsh\LaravelZero\Context\ZeroContext;

return [
    'zero_version' => '1.8.0',
    'context' => [
        'class' => ZeroContext::class,
        'resolver' => NullZeroContextResolver::class,
        'user_id_field' => 'user_id',
    ],
    'discovery' => [
        'directories' => [app_path('Zero'), base_path('modules/*/Zero')],
        'cache' => env('ZERO_REGISTRY_CACHE', false),
        'cache_key' => 'laravel-zero.registry.v1',
    ],
    'routes' => [
        'enabled' => true,
        'prefix' => 'zero',
        'middleware' => ['web', 'auth'],
    ],
    'generation' => [
        'output_directory' => resource_path('js/zero/generated'),
        'generate_schema' => true,
        'schema_path' => resource_path('js/zero/schema.ts'),
    ],
    'database' => [
        'connection' => null,
        'mutation_results_table' => 'zero_mutation_results',
        'clients_table' => 'zero_clients',
    ],
];
