<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Training;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ItemKnnTrainer
{
    /**
     * Train item-item co-occurrence kNN from relevance_interactions.
     *
     * @return array{neighbors_computed: int, items_trained: int, model_version: string}
     */
    public function train(
        string $index = 'default',
        int $minSupport = 2,
        float $shrinkage = 20.0,
        int $topNeighbors = 50,
        int $historyCap = 200,
    ): array {
        if (!Schema::hasTable('relevance_interactions') || !Schema::hasTable('relevance_item_neighbors')) {
            return [
                'neighbors_computed' => 0,
                'items_trained' => 0,
                'model_version' => 'none',
            ];
        }

        $modelVersion = Carbon::now()->toIso8601String();

        // 1. Fetch user-item interactions with weights
        $rows = DB::table('relevance_interactions')
            ->select('user_ref', 'item_id', DB::raw('SUM(COALESCE(weight, 1.0)) as weight'), DB::raw('MAX(occurred_at) as latest'))
            ->where('index', $index)
            ->whereNotNull('user_ref')
            ->where('weight', '>', 0)
            ->groupBy('user_ref', 'item_id')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'neighbors_computed' => 0,
                'items_trained' => 0,
                'model_version' => $modelVersion,
            ];
        }

        // Group by user, sort by latest DESC, cap to historyCap
        $userHistories = [];
        $itemUserCounts = [];
        $itemStats = [];

        foreach ($rows as $row) {
            $user = (string) $row->user_ref;
            $item = (string) $row->item_id;
            $weight = (float) $row->weight;

            $userHistories[$user][] = [
                'item_id' => $item,
                'weight' => $weight,
                'latest' => (string) $row->latest,
            ];

            $itemStats[$item] = ($itemStats[$item] ?? 0.0) + $weight;
        }

        // Cap per user history & count unique users per item
        $cappedUserItems = [];
        foreach ($userHistories as $user => $history) {
            usort($history, static fn ($a, $b) => strcmp($b['latest'], $a['latest']));
            $items = array_slice($history, 0, $historyCap);
            $uniqueItemIds = array_values(array_unique(array_map(static fn ($h) => $h['item_id'], $items)));
            $cappedUserItems[$user] = $uniqueItemIds;

            foreach ($uniqueItemIds as $id) {
                $itemUserCounts[$id] = ($itemUserCounts[$id] ?? 0) + 1;
            }
        }

        // 2. Count pair co-occurrences
        $pairCounts = []; // [item_i => [item_j => co_count]]
        foreach ($cappedUserItems as $items) {
            $n = count($items);
            if ($n < 2) {
                continue;
            }

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $itemA = $items[$i];
                    $itemB = $items[$j];

                    // Symmetric co-occurrence
                    $pairCounts[$itemA][$itemB] = ($pairCounts[$itemA][$itemB] ?? 0) + 1;
                    $pairCounts[$itemB][$itemA] = ($pairCounts[$itemB][$itemA] ?? 0) + 1;
                }
            }
        }

        // 3. Compute shrunk cosine similarity: co(i,j) / (sqrt(n_i * n_j) + lambda)
        $records = [];
        $neighborsCount = 0;
        $itemsTrained = 0;

        foreach ($pairCounts as $itemA => $neighbors) {
            $scores = [];
            $ni = $itemUserCounts[$itemA] ?? 1;

            foreach ($neighbors as $itemB => $co) {
                if ($co < $minSupport) {
                    continue;
                }

                $nj = $itemUserCounts[$itemB] ?? 1;
                $sim = $co / (sqrt($ni * $nj) + $shrinkage);
                $scores[$itemB] = $sim;
            }

            if (empty($scores)) {
                continue;
            }

            arsort($scores);
            $top = array_slice($scores, 0, $topNeighbors, true);

            foreach ($top as $neighborId => $sim) {
                $records[] = [
                    'index' => $index,
                    'algo' => 'item_knn',
                    'item_id' => $itemA,
                    'neighbor_id' => (string) $neighborId,
                    'score' => (float) $sim,
                    'model_version' => $modelVersion,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $neighborsCount++;
            }

            $itemsTrained++;
        }

        // 4. Batch insert into relevance_item_neighbors
        if (!empty($records)) {
            // Delete old model version or current index entries
            DB::table('relevance_item_neighbors')
                ->where('index', $index)
                ->where('algo', 'item_knn')
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('relevance_item_neighbors')->insert($chunk);
            }
        }

        // 5. Update relevance_item_stats with popularity scores
        if (Schema::hasTable('relevance_item_stats') && !empty($itemStats)) {
            $statsRecords = [];
            foreach ($itemStats as $item => $weight) {
                $statsRecords[] = [
                    'index' => $index,
                    'item_id' => $item,
                    'popularity_score' => $weight,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            DB::table('relevance_item_stats')->where('index', $index)->delete();
            foreach (array_chunk($statsRecords, 500) as $chunk) {
                DB::table('relevance_item_stats')->insert($chunk);
            }
        }

        return [
            'neighbors_computed' => $neighborsCount,
            'items_trained' => $itemsTrained,
            'model_version' => $modelVersion,
        ];
    }
}
