<?php

namespace NickWelsh\LaravelZero\Commands;

use Illuminate\Console\Command;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;

final class ClearRegistryCommand extends Command
{
    protected $signature = 'zero:clear';

    protected $description = 'Clear Zero discovery cache';

    public function handle(ZeroRegistry $registry): int
    {
        $registry->clear();
        $this->components->info('Zero registry cleared.');

        return self::SUCCESS;
    }
}
