<?php

namespace NickWelsh\LaravelZero\Commands;

use Illuminate\Console\Command;
use NickWelsh\LaravelZero\Compiler\TypeScript\ZeroTypeScriptGenerator;

final class CheckCommand extends Command
{
    protected $signature = 'zero:check';

    protected $description = 'Check generated Zero files';

    public function handle(ZeroTypeScriptGenerator $generator): int
    {
        $stale = $generator->stale();
        if ($stale !== []) {
            $this->components->error('Stale Zero files: '.implode(', ', $stale));

            return self::FAILURE;
        }
        $this->components->info('Generated Zero files current.');

        return self::SUCCESS;
    }
}
