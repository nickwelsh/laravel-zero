<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('generates native schema and runtime models from the same column plan', function (): void {
    /** @var TestCase $this */
    $output = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
    $this->assertStringContainsString('enableLegacyQueries: false', $output);
    $this->assertStringContainsString('enableLegacyMutators: false', $output);
    $this->assertStringContainsString('export const Task = models.Task', $output);
    $this->assertStringContainsString('createZeroForLaravel(schema, definitions)', $output);
    $this->assertStringNotContainsString('secret', $output);
    $this->assertSame($output, (new TypeScriptGenerator)->generate(new ModelRegistry(array_reverse($this->models()))));
});
