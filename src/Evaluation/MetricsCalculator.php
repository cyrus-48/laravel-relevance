<?php

declare(strict_types=1);

namespace Relevance\Evaluation;

final class MetricsCalculator
{
    /**
     * Compute Normalized Discounted Cumulative Gain (NDCG@K).
     *
     * @param list<string> $retrievedIds
     * @param array<string, int> $judgments doc_id => grade (0-3)
     */
    public function ndcg(array $retrievedIds, array $judgments, int $k = 10): float
    {
        if (empty($judgments)) {
            return 1.0;
        }

        $slice = array_slice($retrievedIds, 0, $k);

        // DCG@K
        $dcg = 0.0;
        foreach ($slice as $i => $docId) {
            $grade = $judgments[$docId] ?? 0;
            if ($grade > 0) {
                $rank = $i + 1;
                $dcg += ((2 ** $grade) - 1) / log($rank + 1, 2);
            }
        }

        // IDCG@K (Ideal DCG)
        $idealGrades = array_values($judgments);
        rsort($idealGrades);
        $idealSlice = array_slice($idealGrades, 0, $k);

        $idcg = 0.0;
        foreach ($idealSlice as $i => $grade) {
            if ($grade > 0) {
                $rank = $i + 1;
                $idcg += ((2 ** $grade) - 1) / log($rank + 1, 2);
            }
        }

        if ($idcg <= 0.0) {
            return $dcg > 0.0 ? 1.0 : 1.0;
        }

        return min(1.0, max(0.0, $dcg / $idcg));
    }

    /**
     * Compute Reciprocal Rank (MRR component for single query).
     *
     * @param list<string> $retrievedIds
     * @param array<string, int> $judgments doc_id => grade
     */
    public function reciprocalRank(array $retrievedIds, array $judgments): float
    {
        foreach ($retrievedIds as $i => $docId) {
            $grade = $judgments[$docId] ?? 0;
            if ($grade >= 1) {
                return 1.0 / ($i + 1);
            }
        }

        return 0.0;
    }

    /**
     * Compute Recall@K.
     *
     * @param list<string> $retrievedIds
     * @param array<string, int> $judgments doc_id => grade
     */
    public function recall(array $retrievedIds, array $judgments, int $k = 50): float
    {
        $relevant = array_filter($judgments, static fn (int $g) => $g >= 1);
        $totalRelevant = count($relevant);

        if ($totalRelevant === 0) {
            return 1.0;
        }

        $slice = array_slice($retrievedIds, 0, $k);
        $hits = 0;

        foreach ($slice as $docId) {
            if (($judgments[$docId] ?? 0) >= 1) {
                $hits++;
            }
        }

        return min(1.0, (float) $hits / (float) $totalRelevant);
    }

    /**
     * Compute Average Precision (MAP component for single query).
     *
     * @param list<string> $retrievedIds
     * @param array<string, int> $judgments doc_id => grade
     */
    public function averagePrecision(array $retrievedIds, array $judgments): float
    {
        $relevant = array_filter($judgments, static fn (int $g) => $g >= 1);
        $totalRelevant = count($relevant);

        if ($totalRelevant === 0) {
            return 1.0;
        }

        $runningHits = 0;
        $sumPrecision = 0.0;

        foreach ($retrievedIds as $i => $docId) {
            if (($judgments[$docId] ?? 0) >= 1) {
                $runningHits++;
                $precisionAtRank = (float) $runningHits / (float) ($i + 1);
                $sumPrecision += $precisionAtRank;
            }
        }

        return min(1.0, $sumPrecision / (float) $totalRelevant);
    }
}
