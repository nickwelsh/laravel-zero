<?php

namespace NickWelsh\LaravelZero\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Compiler\TypeScript\ZeroTypeScriptGenerator;
use NickWelsh\LaravelZero\Installation\FrontendScaffolder;
use NickWelsh\LaravelZero\Support\GeneratedPaths;
use Throwable;

final class GenerateCommand extends Command
{
    private const HEADER = "// This file is generated. Do not edit directly.\n\n";

    protected $signature = 'zero:generate';

    protected $description = 'Generate Zero TypeScript definitions';

    public function handle(ZeroTypeScriptGenerator $generator, FrontendScaffolder $frontend, Filesystem $files): int
    {
        try {
            $schemaPath = GeneratedPaths::schema();
            if (config('laravel-zero.generation.generate_schema') === true) {
                $status = $this->call('generate:zero-schema', ['--path' => $schemaPath]);
                if ($status !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
            $this->addGeneratedHeader($files, $schemaPath);
            $rendered = $generator->render();
            $changed = $generator->write();
            $scaffolded = $frontend->scaffold();
            $this->components->info($changed === [] ? 'Zero files unchanged.' : 'Generated '.count($changed).' Zero files.');
            if ($scaffolded !== []) {
                $this->components->info('Scaffolded '.count($scaffolded).' Zero frontend '.(count($scaffolded) === 1 ? 'file.' : 'files.'));
            }
            if ($rendered['notices'] !== []) {
                $this->components->info('Some validation rules are enforced only by Laravel:');
                foreach ($rendered['notices'] as $path => $rules) {
                    $this->line("  {$path}: ".implode(', ', $rules));
                }
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function addGeneratedHeader(Filesystem $files, string $path): void
    {
        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);
        if (! str_starts_with($contents, self::HEADER)) {
            $files->put($path, self::HEADER.$contents);
        }
    }
}
