<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Data\SearchRequest;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;

class ExperimentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('exp_products'), 'exp_products_test');
        $driver->swapAlias('exp_products', 'exp_products_test');

        $driver->upsert('exp_products', [
            new Document(id: '1', version: 1, fields: ['title' => 'Sneakers Pro']),
            new Document(id: '2', version: 1, fields: ['title' => 'Sneakers Lite']),
        ]);
    }

    public function test_experiment_creation_and_variant_assignment(): void
    {
        $exp = Relevance::experiments()->create(
            name: 'rank-v8',
            variants: [
                'control' => 'exp_products.control',
                'v8' => 'exp_products.v8',
            ],
            split: [50, 50],
            index: 'exp_products'
        );

        $this->assertSame('rank-v8', $exp->name);
        $this->assertTrue($exp->isRunning());

        // Perform searches with different user contexts
        $user1Search = Relevance::index('exp_products')
            ->query('sneakers')
            ->for('user_1')
            ->get();

        $this->assertNotNull($user1Search->variant);
        $this->assertContains($user1Search->variant, ['control', 'v8']);

        // Check exposure was logged at serve time
        $exposure = DB::table('relevance_experiment_exposures')
            ->where('experiment_name', 'rank-v8')
            ->where('unit_id', 'user_1')
            ->first();

        $this->assertNotNull($exposure);
        $this->assertSame($user1Search->variant, $exposure->variant);
        $this->assertSame($user1Search->searchId, $exposure->search_id);

        // Report
        $report = Relevance::experiments()->report('rank-v8');
        $this->assertSame('rank-v8', $report['experiment']);
        $this->assertGreaterThan(0, array_sum($report['exposures']));
    }

    public function test_cli_benchmark_command(): void
    {
        $this->artisan('relevance:benchmark', [
            '--index' => 'exp_products',
            '--count' => 10,
            '--queries' => 'sneakers,pro,lite',
        ])
            ->expectsOutputToContain('Benchmarking')
            ->expectsOutputToContain('Throughput')
            ->expectsOutputToContain('p50 Latency')
            ->expectsOutputToContain('Benchmark completed successfully')
            ->assertExitCode(0);
    }
}
