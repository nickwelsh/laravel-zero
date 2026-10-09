<?php

namespace LaravelZero\Tests\Feature;

use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Tests\TestCase;

uses(TestCase::class);

test('generates native schema and runtime models from the same column plan', function (): void {
        $output = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
        $this->assertStringContainsString('enableLegacyQueries: false', $output);
        $this->assertStringContainsString('enableLegacyMutators: false', $output);
        $this->assertStringContainsString('export const Task = models.Task', $output);
        $this->assertStringContainsString('createLaravelZero(schema, definitions)', $output);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertSame($output, (new TypeScriptGenerator)->generate(new ModelRegistry(array_reverse($this->models()))));
    });

