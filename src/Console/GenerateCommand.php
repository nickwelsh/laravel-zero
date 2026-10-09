<?php

declare(strict_types=1);

namespace LaravelZero\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;

final class GenerateCommand extends Command
{
    protected $signature = 'zero:generate {--output= : Generated TypeScript path} {--check : Fail if output is missing or out of date}';

    protected $description = 'Generate native Zero schemas, typed models, and automatic CRUD contracts';

    public function handle(ModelRegistry $registry, TypeScriptGenerator $generator, Filesystem $files): int
    {
        $path = $this->option('output') ?: config()->string('zero.output');
        if (! is_string($path)) {
            $this->error('Expected a string output.');

            return self::FAILURE;
        }

        $source = $generator->generate($registry);
        foreach ($registry->all() as $model) {
            foreach ($model->diagnostics as $diagnostic) {
                $this->warn($diagnostic);
            }
        }

        if ($this->option('check')) {
            if (! $files->exists($path) || $files->get($path) !== $source) {
                $this->error('Zero contracts are out of date. Run zero:generate.');

                return self::FAILURE;
            }

            $this->info('Zero contracts are current.');

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->replace($path, $source);
        $this->info("Generated {$path}.");

        return self::SUCCESS;
    }
}
