<?php

declare(strict_types=1);

namespace LaravelZero\Console;

use Illuminate\Console\Command;
use LaravelZero\Publication\PublicationManager;

final class SyncPublicationCommand extends Command
{
    protected $signature = 'zero:sync-publication {--name= : Publication name} {--dry-run : Print SQL without changing the publication}';

    protected $description = 'Synchronize a dedicated PostgreSQL publication with generated Zero columns';

    public function handle(PublicationManager $manager): int
    {
        $name = $this->option('name') ?: config()->string('zero.publication');
        if (! is_string($name)) {
            $this->error('Expected a string name.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($manager->plan($name) as $statement) {
                $this->line($statement.';');
            }
        } else {
            $manager->sync($name);
            $this->info("Synchronized publication {$name}.");
        }

        return self::SUCCESS;
    }
}
