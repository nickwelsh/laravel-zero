<?php

declare(strict_types=1);

namespace LaravelZero\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;

final class GenerateCommand extends Command
{
    protected $signature = 'zero:generate {--output= : Generated TypeScript path} {--react : Generate a sibling React provider} {--check : Fail if output is missing or out of date}';

    protected $description = 'Generate native Zero schemas, typed models, and automatic CRUD contracts';

    public function handle(ModelRegistry $registry, TypeScriptGenerator $generator, Filesystem $files): int
    {
        $path = $this->option('output') ?: config()->string('zero.output');
        if (! is_string($path)) {
            $this->error('Expected a string output.');

            return self::FAILURE;
        }

        $source = $generator->generate($registry);
        $outputs = [$path => $source];
        $react = $this->option('react') || config()->boolean('zero.react');
        $config = dirname($path).'/zero.config.ts';
        if ($react) {
            $base = pathinfo($path, PATHINFO_FILENAME);
            $outputs[dirname($path).'/'.$base.'.react.ts'] = $generator->generateReact('./'.$base, './zero.config');
        }

        foreach ($registry->all() as $model) {
            foreach ($model->diagnostics as $diagnostic) {
                $this->warn($diagnostic);
            }
        }

        if ($this->option('check')) {
            foreach ($outputs as $output => $content) {
                if ($files->exists($output) && $files->get($output) === $content) {
                    continue;
                }

                $this->error('Zero contracts are out of date. Run zero:generate.');

                return self::FAILURE;
            }

            if ($react && ! $files->exists($config)) {
                $this->error('Missing zero.config.ts connection options. Run zero:generate --react.');

                return self::FAILURE;
            }

            $this->info('Zero contracts are current.');

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        foreach ($outputs as $output => $content) {
            $files->replace($output, $content);
            $this->info("Generated {$output}.");
        }

        if ($react && ! $files->exists($config)) {
            $files->replace($config, "import type {ProviderOptions} from '@laravel-zero/client/react';\n\n// Public connection options only; never put the cache API key here.\nexport const options = {cacheURL: 'http://localhost:4848'} satisfies ProviderOptions;\n");
            $this->info("Created {$config}. Configure your public cache URL here; this file will not be overwritten.");
        }

        return self::SUCCESS;
    }
}
