<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Evaluation\EvaluationRunner;
use Relevance\Evaluation\MetricsCalculator;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;

class OfflineEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        // Seed documents in memory driver
        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('eval_products'), 'eval_products_test');
        $driver->swapAlias('eval_products', 'eval_products_test');

        $driver->upsert('eval_products', [
            new Document(id: 'doc_1', version: 1, fields: ['title' => 'Wireless Noise Canceling Headphones', 'in_stock' => true]),
            new Document(id: 'doc_2', version: 1, fields: ['title' => 'Bluetooth Earphones', 'in_stock' => true]),
            new Document(id: 'doc_3', version: 1, fields: ['title' => 'Wired Earbuds', 'in_stock' => false]),
            new Document(id: 'doc_4', version: 1, fields: ['title' => 'Gaming Mouse', 'in_stock' => true]),
        ]);
    }

    public function test_ndcg_and_metrics_math(): void
    {
        $calc = new MetricsCalculator();

        // Ideal ranking: doc_1 (grade 3), doc_2 (grade 2), doc_3 (grade 1)
        $judgments = ['doc_1' => 3, 'doc_2' => 2, 'doc_3' => 1];

        // Perfect retrieval
        $perfectNdcg = $calc->ndcg(['doc_1', 'doc_2', 'doc_3'], $judgments, k: 10);
        $this->assertEqualsWithDelta(1.0, $perfectNdcg, 0.001);

        // Inverted retrieval: doc_3, doc_2, doc_1
        $invertedNdcg = $calc->ndcg(['doc_3', 'doc_2', 'doc_1'], $judgments, k: 10);
        $this->assertLessThan(0.85, $invertedNdcg);

        // MRR
        $mrr = $calc->reciprocalRank(['doc_x', 'doc_1'], $judgments);
        $this->assertEqualsWithDelta(0.5, $mrr, 0.001); // 1 / 2

        // Recall@2
        $recall = $calc->recall(['doc_1', 'doc_2'], $judgments, k: 2);
        $this->assertEqualsWithDelta(2 / 3, $recall, 0.001);
    }

    public function test_evaluation_runner_and_ci_gate_pass(): void
    {
        $runner = new EvaluationRunner();

        $runner->seedJudgments('eval_products', 'default', [
            'wireless headphones' => [
                'doc_1' => 3,
                'doc_2' => 2,
            ],
        ]);

        $result = $runner->evaluate(
            index: 'eval_products',
            judgmentSet: 'default',
            profile: 'default',
            minNdcg: 0.50,
        );

        $this->assertTrue($result->passed);
        $this->assertGreaterThanOrEqual(0.50, (float) $result->metrics['ndcg_10']);
        $this->assertSame(1, $result->metrics['total_queries']);
    }

    public function test_evaluation_gate_blocks_a_deliberately_bad_profile(): void
    {
        $runner = new EvaluationRunner();

        // Query expects doc_1 to be top grade
        $runner->seedJudgments('eval_products', 'gate_test', [
            'wireless headphones' => [
                'doc_1' => 3,
                'doc_2' => 1,
            ],
        ]);

        // Demand unrealistic or strict threshold where bad profile fails
        $result = $runner->evaluate(
            index: 'eval_products',
            judgmentSet: 'gate_test',
            profile: 'default',
            minNdcg: 0.9999, // Deliberately high gate to block
        );

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->failureReasons);
    }

    public function test_relevance_eval_command(): void
    {
        $runner = new EvaluationRunner();
        $runner->seedJudgments('eval_products', 'cli_test', [
            'bluetooth earphones' => [
                'doc_2' => 3,
            ],
        ]);

        $this->artisan('relevance:eval', [
            '--index' => 'eval_products',
            '--judgments' => 'cli_test',
            '--profile' => 'default',
            '--min-ndcg' => '0.40',
        ])
            ->expectsOutputToContain('Relevance Evaluation')
            ->expectsOutputToContain('NDCG@10')
            ->expectsOutputToContain('Evaluation Gate PASSED')
            ->assertExitCode(0);
    }
}
