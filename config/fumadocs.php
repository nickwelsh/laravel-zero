<?php

declare(strict_types=1);

return [
    'title' => 'Laravel Zero',
    'description' => 'Laravel-first Zero queries, mutations, and generated TypeScript.',
    'output' => base_path('docs'),
    'base_url' => '/docs',
    'framework' => 'astro',

    'source' => [
        'paths' => [base_path('src')],
        'exclude' => ['*/vendor/*', '*/node_modules/*', '*/tests/*'],
        'visibility' => ['public', 'protected'],
        'include_internal' => false,
    ],

    'api' => [
        'directory' => 'content/docs/api',
        'source_url' => 'https://github.com/nickwelsh/laravel-zero/blob/{branch}',
        'source_branch' => 'main',
    ],

    'manual' => [
        'directory' => 'content/docs/guide',
    ],

    'openapi' => [
        'enabled' => false,
        'specs' => [],
        'directory' => 'content/docs/openapi',
    ],

    'node' => [
        'package_manager' => 'npm',
        'install' => true,
    ],
];
