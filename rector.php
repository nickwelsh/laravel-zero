<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])
    ->withSkip([
        // Preserve exception codes and framework property initialization checks.
        Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector::class,
        Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector::class,
        // Pint owns import and trait formatting.
        Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector::class,
    ])
    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true, earlyReturn: true)
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withPhpSets(php83: true);
