<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Jobs\DrainOutbox;

class RelevanceSyncCommand extends Command
{
    protected $signature = 'relevance:sync {--index= : Specific index to drain}';
    protected $description = 'Drain pending outbox operations and sync to search drivers';

    public function handle(): int
    {
        $index = $this->option('index');
        $this->info('Draining pending relevance outbox records...');

        DrainOutbox::dispatchSync(is_string($index) ? $index : null);

        $this->info('Outbox drain completed successfully.');

        return self::SUCCESS;
    }
}
