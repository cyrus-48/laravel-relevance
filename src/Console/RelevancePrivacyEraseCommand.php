<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Support\Privacy\PrivacyManager;

class RelevancePrivacyEraseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:privacy:erase
                            {user : The user identifier to erase}
                            {--force : Force the erasure without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Erase all analytics, interaction, and behavioral data for a user subject (GDPR erasure)';

    public function handle(PrivacyManager $manager): int
    {
        $rawUser = $this->argument('user');
        $user = is_string($rawUser) ? $rawUser : (is_scalar($rawUser) ? (string) $rawUser : '');

        if (!$this->option('force') && !$this->confirm("Are you sure you want to permanently delete all relevance data for user [{$user}]?")) {
            $this->warn('Erasure cancelled.');
            return self::SUCCESS;
        }

        $this->info("Erasing data for subject [{$user}]...");

        $report = $manager->erase($user);

        $rows = [];
        foreach ($report->deletedCounts as $table => $count) {
            $rows[] = [$table, $count];
        }

        $this->table(['Table', 'Deleted Records'], $rows);
        $this->info("Total records erased: {$report->totalDeleted}");

        return self::SUCCESS;
    }
}
