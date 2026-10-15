<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Recommendations\Training\ItemKnnTrainer;

class RelevanceRecTrainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:rec:train
                            {--index=default : The index name}
                            {--algo=item_knn : The algorithm to train}
                            {--min-support=2 : Minimum co-occurrence count}
                            {--shrinkage=20 : Shrinkage parameter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Train item-kNN recommendation models and popularity stats from interaction events';

    public function handle(ItemKnnTrainer $trainer): int
    {
        $rawIndex = $this->option('index');
        $index = is_string($rawIndex) ? $rawIndex : 'default';

        $rawMinSupport = $this->option('min-support');
        $minSupport = is_numeric($rawMinSupport) ? (int) $rawMinSupport : 2;

        $rawShrinkage = $this->option('shrinkage');
        $shrinkage = is_numeric($rawShrinkage) ? (float) $rawShrinkage : 20.0;

        $this->info("Training recommendations for index [{$index}] (algo: item_knn, min_support: {$minSupport}, shrinkage: {$shrinkage})...");

        $result = $trainer->train(
            index: $index,
            minSupport: $minSupport,
            shrinkage: $shrinkage,
        );

        $this->table(
            ['Metric', 'Value'],
            [
                ['Index', $index],
                ['Items Trained', (string) $result['items_trained']],
                ['Neighbors Computed', (string) $result['neighbors_computed']],
                ['Model Version', $result['model_version']],
            ]
        );

        $this->info('Recommendation training completed successfully.');

        return self::SUCCESS;
    }
}
