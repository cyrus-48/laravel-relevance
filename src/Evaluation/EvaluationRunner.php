<?php

declare(strict_types=1);

namespace Relevance\Evaluation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Facades\Relevance;

class EvaluationRunner
{
    public function __construct(
        private readonly MetricsCalculator $calculator = new MetricsCalculator(),
    ) {}

    /**
     * @param array<string, array<string, int>>|null $overrideJudgments query => [doc_id => grade]
     */
    public function evaluate(
        string $index = 'default',
        string $judgmentSet = 'default',
        string $profile = 'default',
        ?string $baselineProfile = null,
        float $minNdcg = 0.50,
        float $maxZeroResult = 0.10,
        ?array $overrideJudgments = null,
    ): EvaluationResult {
        $judgments = $overrideJudgments ?? $this->loadJudgmentsFromDatabase($index, $judgmentSet);

        if (empty($judgments)) {
            return new EvaluationResult(
                index: $index,
                judgmentSet: $judgmentSet,
                profile: $profile,
                baselineProfile: $baselineProfile,
                metrics: [
                    'ndcg_10' => 1.0,
                    'mrr' => 1.0,
                    'recall_50' => 1.0,
                    'map' => 1.0,
                    'zero_result_rate' => 0.0,
                    'total_queries' => 0,
                ],
                passed: true,
            );
        }

        $totalQueries = count($judgments);
        $ndcgSum = 0.0;
        $mrrSum = 0.0;
        $recallSum = 0.0;
        $mapSum = 0.0;
        $zeroResultsCount = 0;

        $wins = [];
        $losses = [];
        $ties = [];

        foreach ($judgments as $query => $docGrades) {
            // Evaluate current profile
            $search = Relevance::index($index)
                ->query($query)
                ->profile($profile)
                ->limit(50)
                ->get();

            $retrievedIds = array_map(static fn ($h) => (string) $h->id, $search->hits);

            if (empty($retrievedIds)) {
                $zeroResultsCount++;
            }

            $qNdcg = $this->calculator->ndcg($retrievedIds, $docGrades, k: 10);
            $qMrr = $this->calculator->reciprocalRank($retrievedIds, $docGrades);
            $qRecall = $this->calculator->recall($retrievedIds, $docGrades, k: 50);
            $qMap = $this->calculator->averagePrecision($retrievedIds, $docGrades);

            $ndcgSum += $qNdcg;
            $mrrSum += $qMrr;
            $recallSum += $qRecall;
            $mapSum += $qMap;

            // Baseline comparison
            if ($baselineProfile !== null) {
                $baseSearch = Relevance::index($index)
                    ->query($query)
                    ->profile($baselineProfile)
                    ->limit(50)
                    ->get();

                $baseRetrievedIds = array_map(static fn ($h) => (string) $h->id, $baseSearch->hits);
                $baseNdcg = $this->calculator->ndcg($baseRetrievedIds, $docGrades, k: 10);

                $delta = $qNdcg - $baseNdcg;
                if ($delta > 0.02) {
                    $wins[] = $query;
                } elseif ($delta < -0.02) {
                    $losses[] = $query;
                } else {
                    $ties[] = $query;
                }
            }
        }

        $meanNdcg = $ndcgSum / $totalQueries;
        $meanMrr = $mrrSum / $totalQueries;
        $meanRecall = $recallSum / $totalQueries;
        $meanMap = $mapSum / $totalQueries;
        $zeroResultRate = (float) $zeroResultsCount / (float) $totalQueries;

        $failureReasons = [];
        if ($meanNdcg < $minNdcg) {
            $failureReasons[] = sprintf('Mean NDCG@10 (%.4f) fell below threshold (%.4f)', $meanNdcg, $minNdcg);
        }
        if ($zeroResultRate > $maxZeroResult) {
            $failureReasons[] = sprintf('Zero result rate (%.2f%%) exceeded threshold (%.2f%%)', $zeroResultRate * 100, $maxZeroResult * 100);
        }

        $passed = empty($failureReasons);

        $metrics = [
            'ndcg_10' => round($meanNdcg, 4),
            'mrr' => round($meanMrr, 4),
            'recall_50' => round($meanRecall, 4),
            'map' => round($meanMap, 4),
            'zero_result_rate' => round($zeroResultRate, 4),
            'total_queries' => $totalQueries,
        ];

        $diff = [
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
        ];

        // Persist run to DB
        $this->persistRun($index, $judgmentSet, $profile, $baselineProfile, $metrics, $diff, $passed);

        return new EvaluationResult(
            index: $index,
            judgmentSet: $judgmentSet,
            profile: $profile,
            baselineProfile: $baselineProfile,
            metrics: $metrics,
            diff: $diff,
            passed: $passed,
            failureReasons: $failureReasons,
        );
    }

    /**
     * @param array<string, array<string, int>> $judgments query => [doc_id => grade]
     */
    public function seedJudgments(string $index, string $judgmentSet, array $judgments): void
    {
        try {
            if (!Schema::hasTable('relevance_judgments')) {
                return;
            }

            foreach ($judgments as $query => $docGrades) {
                foreach ($docGrades as $docId => $grade) {
                    DB::table('relevance_judgments')->updateOrInsert(
                        [
                            'index' => $index,
                            'judgment_set' => $judgmentSet,
                            'query' => $query,
                            'doc_id' => (string) $docId,
                        ],
                        [
                            'grade' => $grade,
                            'source' => 'seed',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        } catch (\Throwable) {
            // Fail-soft
        }
    }

    /**
     * @return array<string, array<string, int>> query => [doc_id => grade]
     */
    private function loadJudgmentsFromDatabase(string $index, string $judgmentSet): array
    {
        try {
            if (!Schema::hasTable('relevance_judgments')) {
                return [];
            }

            $rows = DB::table('relevance_judgments')
                ->where('index', $index)
                ->where('judgment_set', $judgmentSet)
                ->get();

            $judgments = [];
            foreach ($rows as $row) {
                $q = (string) $row->query;
                $doc = (string) $row->doc_id;
                $grade = (int) $row->grade;
                $judgments[$q][$doc] = $grade;
            }

            return $judgments;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, float|int> $metrics
     * @param array{wins: list<string>, losses: list<string>, ties: list<string>} $diff
     */
    private function persistRun(
        string $index,
        string $judgmentSet,
        string $profile,
        ?string $baselineProfile,
        array $metrics,
        array $diff,
        bool $passed,
    ): void {
        try {
            if (Schema::hasTable('relevance_eval_runs')) {
                DB::table('relevance_eval_runs')->insert([
                    'index' => $index,
                    'judgment_set' => $judgmentSet,
                    'profile' => $profile,
                    'baseline_profile' => $baselineProfile,
                    'metrics' => json_encode($metrics),
                    'diff' => json_encode($diff),
                    'passed' => $passed,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Fail-soft
        }
    }
}
