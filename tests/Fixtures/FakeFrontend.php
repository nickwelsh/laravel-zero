<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Frontend\Frontend;
use NickWelsh\LaravelZero\Support\GeneratedPaths;

final readonly class FakeFrontend extends Frontend
{
    protected function generatedFiles(string $outputPath): array
    {
        return [
            'client.ts' => "export const client = 'fake';\n",
        ];
    }

    protected function barrel(string $barrelPath, string $outputPath): string
    {
        return "export * from '".GeneratedPaths::moduleImport($barrelPath, $outputPath.'/client.ts')."';\n";
    }
}
