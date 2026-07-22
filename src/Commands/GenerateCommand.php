<?php

namespace NickWelsh\LaravelZero\Commands;

use Illuminate\Console\Command;
use NickWelsh\LaravelZero\Compiler\TypeScript\ZeroTypeScriptGenerator;
use Throwable;

final class GenerateCommand extends Command
{
    protected $signature = 'zero:generate';

    protected $description = 'Generate Zero TypeScript definitions';

    public function handle(ZeroTypeScriptGenerator $generator): int
    {
        try {
            if (config('laravel-zero.generation.generate_schema') === true) {
                $status = $this->call('generate:zero-schema', ['--path' => config('laravel-zero.generation.schema_path')]);
                if ($status !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
            $rendered = $generator->render();
            $changed = $generator->write();
            $this->components->info($changed === [] ? 'Zero files unchanged.' : 'Generated '.count($changed).' Zero files.');
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
}
