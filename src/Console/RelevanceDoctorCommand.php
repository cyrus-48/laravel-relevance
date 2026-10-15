<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Contracts\Capability;
use Relevance\Drivers\DriverManager;

class RelevanceDoctorCommand extends Command
{
    protected $signature = 'relevance:doctor';
    protected $description = 'Verify configuration, driver health, and capability matrix for Laravel Relevance';

    public function handle(DriverManager $driverManager): int
    {
        $this->info('Diagnosing Laravel Relevance Environment...');
        $this->newLine();

        $defaultDriverName = config('relevance.driver', 'memory');
        $this->line("<comment>Default Search Driver:</comment> {$defaultDriverName}");

        try {
            $driver = $driverManager->driver($defaultDriverName);
            $this->info("✓ Driver [{$defaultDriverName}] initialized successfully.");
        } catch (\Throwable $e) {
            $this->error("✗ Failed to initialize driver [{$defaultDriverName}]: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<comment>Driver Capability Matrix:</comment>');

        $caps = $driver->capabilities();
        $rows = [];
        foreach (Capability::cases() as $capability) {
            $supported = $caps->has($capability);
            $rows[] = [
                $capability->value,
                $supported ? '<info>Supported</info>' : '<fg=gray>Not Supported</>',
            ];
        }

        $this->table(['Capability', 'Status'], $rows);

        $this->newLine();
        $embeddingProvider = config('relevance.embeddings.default', 'fake');
        $this->line("<comment>Default Embedding Provider:</comment> {$embeddingProvider}");

        $indexes = config('relevance.indexes', []);
        $this->line('<comment>Registered Index Definitions:</comment> ' . count($indexes));

        $this->newLine();
        $this->info('Relevance Doctor inspection passed with 0 errors.');

        return self::SUCCESS;
    }
}
