<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;

class RelevanceInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and publish configuration and migrations for Laravel Relevance';

    public function handle(): int
    {
        $this->info('Installing Laravel Relevance...');

        $this->call('vendor:publish', [
            '--tag' => 'relevance-config',
            '--force' => false,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'relevance-migrations',
            '--force' => false,
        ]);

        $this->info('Laravel Relevance scaffolding published successfully.');
        $this->info('Next steps:');
        $this->line('  1. Review <comment>config/relevance.php</comment>');
        $this->line('  2. Run <comment>php artisan migrate</comment>');
        $this->line('  3. Create an index definition: <comment>php artisan make:relevance-index ProductIndex</comment>');
        $this->line('  4. Run health check: <comment>php artisan relevance:doctor</comment>');

        return self::SUCCESS;
    }
}
