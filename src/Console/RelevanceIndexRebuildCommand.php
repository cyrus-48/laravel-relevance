<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Contracts\IndexAdmin;
use Relevance\Drivers\DriverManager;
use Relevance\Indexing\BackfillReport;
use Relevance\Indexing\GenerationManager;
use Relevance\Indexing\IndexBackfiller;
use Relevance\Schema\IndexRegistry;

class RelevanceIndexRebuildCommand extends Command
{
    protected $signature = 'relevance:index:rebuild {index : Name of the index} {--activate=auto : Activation strategy: auto or manual}';
    protected $description = 'Perform a zero-downtime re-index into a new physical generation';

    public function handle(
        IndexRegistry $registry,
        GenerationManager $generationManager,
        IndexBackfiller $backfiller,
        DriverManager $driverManager,
    ): int {
        $rawIndex = $this->argument('index');
        $name = is_string($rawIndex) ? $rawIndex : '';
        $def = $registry->get($name);

        if ($def === null) {
            $this->error("Index definition [{$name}] is not registered.");

            return self::FAILURE;
        }

        $this->info("Creating new physical generation for index [{$name}]...");
        $gen = $generationManager->rebuild($def);
        $this->line("Created generation <comment>#{$gen->generation}</comment> ({$gen->physical_name})");

        $report = $backfiller->backfill($def, $gen->physical_name);
        $this->info("Backfilled {$report->documents} documents into {$gen->physical_name}.");

        if ($report->expectsVectors) {
            $this->line("Embedded {$report->embedded}/{$report->documents} documents ({$report->embeddingFailures} without a vector).");
        }

        if ($this->option('activate') !== 'auto') {
            $this->comment("Generation #{$gen->generation} is building. Run `php artisan relevance:index:swap {$name} {$gen->generation}` to activate.");

            return self::SUCCESS;
        }

        // Activation gate: never auto-swap in a generation that is incomplete.
        $problem = $this->gateProblem($driverManager, $gen->physical_name, $report);
        if ($problem !== null) {
            $this->error("Not activating generation #{$gen->generation}: {$problem}");
            $this->line("The live alias [{$name}] is unchanged. Fix the cause and re-run, or inspect the generation and activate it manually with `relevance:index:swap {$name} {$gen->generation}`.");

            return self::FAILURE;
        }

        $this->info("Activating generation #{$gen->generation}...");
        $generationManager->activate($name, $gen->generation);
        $this->info("Active alias [{$name}] now points to {$gen->physical_name}.");

        return self::SUCCESS;
    }

    private function gateProblem(DriverManager $driverManager, string $physicalName, BackfillReport $report): ?string
    {
        if (!$report->isComplete()) {
            return "only {$report->embedded} of {$report->documents} documents have a vector; activating would silently degrade semantic/hybrid search to text-only.";
        }

        $driver = $driverManager->driver();
        if ($driver instanceof IndexAdmin) {
            $count = $driver->stats($physicalName)->documentCount;
            if ($count < $report->documents) {
                return "the engine reports {$count} documents but {$report->documents} were written.";
            }
        }

        return null;
    }
}
