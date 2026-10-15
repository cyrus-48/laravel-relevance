<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Artifacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ArtifactManager
{
    /**
     * Validate and activate an external model artifact.
     */
    public function activate(string $manifestPath, string $index = 'default', float $minMetric = 0.20): ArtifactActivationResult
    {
        if (!file_exists($manifestPath)) {
            throw new RuntimeException("Manifest file not found at [{$manifestPath}].");
        }

        $manifestContent = file_get_contents($manifestPath);
        if ($manifestContent === false) {
            throw new RuntimeException("Failed reading manifest file at [{$manifestPath}].");
        }

        /** @var array{algo?: string, model_version?: string, metrics?: array<string, float>} $manifest */
        $manifest = json_decode($manifestContent, true);
        $algo = (string) ($manifest['algo'] ?? 'external');
        $modelVersion = (string) ($manifest['model_version'] ?? 'v_' . time());
        $metrics = (array) ($manifest['metrics'] ?? []);

        $ndcg = (float) ($metrics['ndcg@10'] ?? ($metrics['ndcg'] ?? 0.0));

        // Evaluation gate check
        if ($ndcg < $minMetric) {
            $reason = sprintf("Model offline NDCG@10 (%.4f) fell below gate threshold (%.4f)", $ndcg, $minMetric);

            $this->recordArtifactStatus($index, $algo, $modelVersion, $metrics, $manifest, 'rejected');

            return new ArtifactActivationResult(
                passed: false,
                modelVersion: $modelVersion,
                algo: $algo,
                metrics: $metrics,
                failureReason: $reason,
            );
        }

        // Gate passed -> Import neighbors if neighbors.jsonl exists in the same directory
        $dir = dirname($manifestPath);
        $neighborsPath = $dir . '/neighbors.jsonl';

        if (file_exists($neighborsPath)) {
            $this->importNeighborsJsonl($neighborsPath, $index, $algo, $modelVersion);
        }

        $this->recordArtifactStatus($index, $algo, $modelVersion, $metrics, $manifest, 'active');

        return new ArtifactActivationResult(
            passed: true,
            modelVersion: $modelVersion,
            algo: $algo,
            metrics: $metrics,
        );
    }

    private function importNeighborsJsonl(string $path, string $index, string $algo, string $modelVersion): void
    {
        if (!Schema::hasTable('relevance_item_neighbors')) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $records = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array{item: string, neighbors: list<array{id: string, score: float}>} $data */
            $data = json_decode($line, true);
            $itemId = (string) $data['item'];
            $neighbors = (array) $data['neighbors'];

            foreach ($neighbors as $n) {
                $neighborId = (string) $n['id'];
                $score = (float) $n['score'];

                $records[] = [
                    'index' => $index,
                    'algo' => $algo,
                    'item_id' => $itemId,
                    'neighbor_id' => $neighborId,
                    'score' => $score,
                    'model_version' => $modelVersion,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($records) >= 500) {
                    DB::table('relevance_item_neighbors')->insert($records);
                    $records = [];
                }
            }
        }
        fclose($handle);

        if (!empty($records)) {
            DB::table('relevance_item_neighbors')->insert($records);
        }
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $manifest
     */
    private function recordArtifactStatus(
        string $index,
        string $algo,
        string $modelVersion,
        array $metrics,
        array $manifest,
        string $status,
    ): void {
        try {
            if (Schema::hasTable('relevance_model_artifacts')) {
                DB::table('relevance_model_artifacts')->insert([
                    'index' => $index,
                    'algo' => $algo,
                    'model_version' => $modelVersion,
                    'metrics' => json_encode($metrics),
                    'manifest' => json_encode($manifest),
                    'status' => $status,
                    'activated_at' => $status === 'active' ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Fail-soft
        }
    }
}
