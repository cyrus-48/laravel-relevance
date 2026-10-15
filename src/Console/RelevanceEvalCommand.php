<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Evaluation\EvaluationRunner;

class RelevanceEvalCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:eval
                            {--index=default : The index name}
                            {--judgments=default : The judgment set name}
                            {--profile=default : The target profile to evaluate}
                            {--baseline= : Optional baseline profile to compare against}
                            {--min-ndcg=0.50 : Minimum required mean NDCG@10}
                            {--max-zero-result=0.10 : Maximum allowed zero-result rate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run offline relevance evaluation (NDCG@10, MRR, Recall@50, MAP) against a judgment set';

    public function handle(EvaluationRunner $runner): int
    {
        $rawIndex = $this->option('index');
        $index = is_string($rawIndex) ? $rawIndex : 'default';

        $rawJudgments = $this->option('judgments');
        $judgmentSet = is_string($rawJudgments) ? $rawJudgments : 'default';

        $rawProfile = $this->option('profile');
        $profile = is_string($rawProfile) ? $rawProfile : 'default';

        $rawBaseline = $this->option('baseline');
        $baseline = is_string($rawBaseline) && $rawBaseline !== '' ? $rawBaseline : null;

        $rawMinNdcg = $this->option('min-ndcg');
        $minNdcg = is_numeric($rawMinNdcg) ? (float) $rawMinNdcg : 0.50;

        $rawMaxZero = $this->option('max-zero-result');
        $maxZero = is_numeric($rawMaxZero) ? (float) $rawMaxZero : 0.10;

        $this->info("=== Relevance Evaluation: [{$profile}] on index [{$index}] (judgments: {$judgmentSet}) ===");
        if ($baseline !== null) {
            $this->line("<comment>Comparing against baseline:</comment> {$baseline}");
        }

        $result = $runner->evaluate(
            index: $index,
            judgmentSet: $judgmentSet,
            profile: $profile,
            baselineProfile: $baseline,
            minNdcg: $minNdcg,
            maxZeroResult: $maxZero,
        );

        $this->newLine();
        $this->info("--- Metrics (evaluated on {$result->metrics['total_queries']} queries) ---");

        $metricRows = [
            ['NDCG@10', number_format((float) $result->metrics['ndcg_10'], 4), ">= {$minNdcg}"],
            ['MRR', number_format((float) $result->metrics['mrr'], 4), ''],
            ['Recall@50', number_format((float) $result->metrics['recall_50'], 4), ''],
            ['MAP', number_format((float) $result->metrics['map'], 4), ''],
            ['Zero-Result Rate', number_format(((float) $result->metrics['zero_result_rate']) * 100, 2) . '%', "<= " . ($maxZero * 100) . '%'],
        ];

        $this->table(['Metric', 'Value', 'Gate Threshold'], $metricRows);

        if ($baseline !== null) {
            $this->newLine();
            $this->info("--- Win/Loss Comparison vs [{$baseline}] ---");
            $this->line("<info>Wins (+2% NDCG):</info>   " . count($result->diff['wins']));
            $this->line("<error>Losses (-2% NDCG):</error> " . count($result->diff['losses']));
            $this->line("<comment>Ties:</comment>             " . count($result->diff['ties']));
        }

        if (!$result->passed) {
            $this->newLine();
            $this->error("Evaluation Gate Failed:");
            foreach ($result->failureReasons as $reason) {
                $this->error("  - {$reason}");
            }
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Evaluation Gate PASSED successfully.");

        return self::SUCCESS;
    }
}
