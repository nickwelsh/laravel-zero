<?php

use App\Zero\ContextResolver;
use App\Zero\ZeroContext;
use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\Mode;

return [
    'zero_version' => '1.8.0',
    'context' => [
        'class' => ZeroContext::class,
        'resolver' => ContextResolver::class,
        'user_id_field' => 'user_id',
    ],
    'discovery' => [
        'queries' => [app_path('Zero/Queries')],
        'mutators' => [app_path('Zero/Mutators')],
        'cache' => env('ZERO_REGISTRY_CACHE', false),
        'cache_key' => 'laravel-zero.registry.v1',
    ],
    'routes' => [
        'enabled' => true,
        'prefix' => 'zero',
        'middleware' => ['web', 'auth'],
        'except_from_csrf' => true,
    ],
    'generation' => [
        'output_directory' => resource_path('js/zero/generated'),
        'barrel_path' => resource_path('js/zero/index.ts'),
        'generate_schema' => true,
        'schema_path' => resource_path('js/zero/generated/schema.generated.ts'),
        'declaration_style' => 'interface',
        'mode' => Mode::OptOut,
        'model_search_directories' => [app_path('Models')],
        'models' => [],
        'tables' => [],
        'table_name_casing' => Casing::Camel,
        'column_name_casing' => Casing::Camel,
        'use_wayfinder' => false,
    ],
    'database' => [
        'connection' => null,
        'allow_multiple_connections' => false,
        'publication_name' => env('ZERO_APP_PUBLICATIONS'),
    ],
    'frontend' => [
        'framework' => 'react',
        'provider_path' => resource_path('js/zero/generated/provider.generated.tsx'),
        'use_globals' => true,
        'globals_path' => resource_path('js/globals.ts'),
    ],
];
