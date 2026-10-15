<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Drivers\DriverManager;
use Relevance\Indexing\GenerationManager;
use Relevance\Schema\IndexRegistry;

class RelevanceIndexRebuildCommand extends Command
{
    protected $signature = 'relevance:index:rebuild {index : Name of the index} {--activate=auto : Activation strategy: auto or manual}';
    protected $description = 'Perform a zero-downtime re-index into a new physical generation';

    public function handle(
        IndexRegistry $registry,
        GenerationManager $generationManager,
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

        // Backfill source data
        $driver = $driverManager->driver();
        if ($driver instanceof Indexer) {
            $sourceQuery = $def->source();
            if ($sourceQuery !== null) {
                $count = 0;
                $sourceQuery->chunk(200, function ($models) use ($def, $driver, $gen, &$count) {
                    $documents = [];
                    foreach ($models as $model) {
                        if ($def->shouldIndex($model)) {
                            $documents[] = new Document(
                                id: (string) $model->getKey(),
                                version: $def->version($model),
                                fields: $def->toDocument($model),
                                tenant: $def->tenantOf($model),
                            );
                        }
                    }
                    if (!empty($documents)) {
                        $driver->upsert($gen->physical_name, $documents);
                        $count += count($documents);
                    }
                });
                $this->info("Backfilled {$count} documents into {$gen->physical_name}.");
            }
        }

        if ($this->option('activate') === 'auto') {
            $this->info("Activating generation #{$gen->generation}...");
            $generationManager->activate($name, $gen->generation);
            $this->info("Active alias [{$name}] now points to {$gen->physical_name}.");
        } else {
            $this->comment("Generation #{$gen->generation} is building. Run `php artisan relevance:index:swap {$name} {$gen->generation}` to activate.");
        }

        return self::SUCCESS;
    }
}
