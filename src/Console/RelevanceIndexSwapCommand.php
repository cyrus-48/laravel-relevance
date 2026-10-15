<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Indexing\GenerationManager;

class RelevanceIndexSwapCommand extends Command
{
    protected $signature = 'relevance:index:swap {index : Logical index name} {generation : Generation number to activate}';
    protected $description = 'Atomically activate/swap an index generation alias';

    public function handle(GenerationManager $generationManager): int
    {
        $rawIndex = $this->argument('index');
        $index = is_string($rawIndex) ? $rawIndex : '';
        $gen = (int) $this->argument('generation');

        $this->info("Activating generation #{$gen} for index [{$index}]...");
        $generationManager->activate($index, $gen);

        $this->info("Generation #{$gen} successfully activated.");

        return self::SUCCESS;
    }
}
