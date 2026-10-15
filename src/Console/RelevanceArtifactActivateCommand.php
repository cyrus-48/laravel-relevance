<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Recommendations\Artifacts\ArtifactManager;

class RelevanceArtifactActivateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:artifacts:activate
                            {manifest : Path to the manifest.json file}
                            {--index=default : The index name}
                            {--min-metric=0.20 : Minimum evaluation gate threshold}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate and activate an external model artifact with an evaluation gate check';

    public function handle(ArtifactManager $manager): int
    {
        $rawManifest = $this->argument('manifest');
        $manifestPath = is_string($rawManifest) ? $rawManifest : '';

        $rawIndex = $this->option('index');
        $index = is_string($rawIndex) ? $rawIndex : 'default';

        $rawMin = $this->option('min-metric');
        $minMetric = is_numeric($rawMin) ? (float) $rawMin : 0.20;

        $this->info("Validating external model artifact at [{$manifestPath}] for index [{$index}] (gate: >= {$minMetric})...");

        $result = $manager->activate($manifestPath, $index, $minMetric);

        if (!$result->passed) {
            $this->error("Artifact Activation REJECTED: " . ($result->failureReason ?? 'Gate failed.'));
            return self::FAILURE;
        }

        $this->info("Artifact ACTIVATED successfully (model version: {$result->modelVersion}, algo: {$result->algo}).");

        return self::SUCCESS;
    }
}
