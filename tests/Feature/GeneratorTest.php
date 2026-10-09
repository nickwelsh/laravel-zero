<?php

namespace LaravelZero\Tests\Feature;

use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Tests\TestCase;

class GeneratorTest extends TestCase
{
    public function test_generates_native_schema_and_runtime_models_from_the_same_column_plan(): void
    {
        $output = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
        self::assertStringContainsString('enableLegacyQueries: false', $output);
        self::assertStringContainsString('enableLegacyMutators: false', $output);
        self::assertStringContainsString('export const Task = models.Task', $output);
        self::assertStringContainsString('createLaravelZero(schema, definitions)', $output);
        self::assertStringNotContainsString('secret', $output);
        self::assertSame($output, (new TypeScriptGenerator)->generate(new ModelRegistry(array_reverse($this->models()))));
    }
}
