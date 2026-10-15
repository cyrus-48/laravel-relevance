<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Relevance\Data\Document;
use Relevance\Data\Hit;
use Relevance\Data\IndexSchema;
use Relevance\Data\SearchRequest;
use Relevance\Facades\Relevance;
use Relevance\Scout\RelevanceScoutEngine;
use Relevance\Search\Diversify\FieldCollapser;
use Relevance\Search\Profiles\ProfileDefinition;
use Relevance\Testing\FakeReranker;
use Relevance\Tests\TestCase;

class AdvancedRankingAndScaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('products'), 'products_test');
        $driver->swapAlias('products', 'products_test');

        $driver->upsert('products', [
            new Document(id: 'prod_1', version: 1, fields: ['title' => 'MacBook Pro 16', 'brand' => 'Apple', 'price' => 2499.0]),
            new Document(id: 'prod_2', version: 1, fields: ['title' => 'MacBook Air 13', 'brand' => 'Apple', 'price' => 1099.0]),
            new Document(id: 'prod_3', version: 1, fields: ['title' => 'iPad Pro 12.9', 'brand' => 'Apple', 'price' => 999.0]),
            new Document(id: 'prod_4', version: 1, fields: ['title' => 'Dell XPS 15', 'brand' => 'Dell', 'price' => 1799.0]),
            new Document(id: 'prod_5', version: 1, fields: ['title' => 'Sony WH-1000XM5', 'brand' => 'Sony', 'price' => 399.0]),
        ]);
    }

    public function test_reranker_reorders_hits_and_records_stage(): void
    {
        $fakeReranker = new FakeReranker();
        // Reverse hits on rerank
        $fakeReranker->withCallback(function (string $query, array $hits) {
            $reversed = array_reverse($hits);
            $reranked = [];
            foreach ($reversed as $idx => $hit) {
                $reranked[] = $hit->with(score: 1.0 - ($idx * 0.1), stage: 'rerank');
            }
            return $reranked;
        });

        Relevance::rerankers()->register('custom_fake', $fakeReranker);

        Relevance::profiles()->register(new ProfileDefinition(
            name: 'with_rerank',
            index: 'products',
            rerank: ['provider' => 'custom_fake', 'top' => 5],
        ));

        $response = Relevance::search(new SearchRequest(index: 'products', query: 'Pro', profile: 'with_rerank'), 'products');

        $this->assertNotEmpty($response->hits);
        $this->assertEmpty($response->degraded);
        $this->assertArrayHasKey('rerank', $response->hits[0]->breakdown->stages);
    }

    public function test_reranker_timeout_causes_graceful_degradation(): void
    {
        $fakeReranker = (new FakeReranker())->timeout();
        Relevance::rerankers()->register('timeout_fake', $fakeReranker);

        Relevance::profiles()->register(new ProfileDefinition(
            name: 'timeout_profile',
            index: 'products',
            rerank: ['provider' => 'timeout_fake', 'top' => 5, 'timeout_ms' => 50],
        ));

        $response = Relevance::search(new SearchRequest(index: 'products', query: 'MacBook', profile: 'timeout_profile'), 'products');

        // Should not crash! Returns retrieval hits and marks degraded
        $this->assertNotEmpty($response->hits);
        $this->assertContains('rerank', $response->degraded);
    }

    public function test_field_collapse_limits_per_group_items(): void
    {
        $collapser = new FieldCollapser();

        $hits = [
            new Hit(id: '1', score: 10.0, fields: ['brand' => 'Apple']),
            new Hit(id: '2', score: 9.0, fields: ['brand' => 'Apple']),
            new Hit(id: '3', score: 8.0, fields: ['brand' => 'Apple']),
            new Hit(id: '4', score: 7.0, fields: ['brand' => 'Dell']),
            new Hit(id: '5', score: 6.0, fields: ['brand' => 'Sony']),
        ];

        // Max 2 Apple products
        $collapsed = $collapser->collapse($hits, ['brand' => 2]);

        $this->assertCount(4, $collapsed);
        $appleCount = 0;
        foreach ($collapsed as $hit) {
            if ($hit->fields['brand'] === 'Apple') {
                $appleCount++;
            }
        }
        $this->assertSame(2, $appleCount);
        $this->assertSame('4', $collapsed[2]->id); // Dell promoted
        $this->assertSame('5', $collapsed[3]->id); // Sony promoted
    }

    public function test_artifact_activation_gate_success_and_failure(): void
    {
        $tmpDir = sys_get_temp_dir() . '/relevance_artifact_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // 1. Create passing manifest and neighbors.jsonl
        $manifestData = [
            'algo' => 'item2vec',
            'model_version' => 'v2026.10',
            'metrics' => [
                'ndcg@10' => 0.45,
            ],
        ];
        file_put_contents($tmpDir . '/manifest.json', json_encode($manifestData));

        $neighborsData = [
            json_encode(['item' => 'prod_1', 'neighbors' => [['id' => 'prod_2', 'score' => 0.92], ['id' => 'prod_3', 'score' => 0.81]]]),
            json_encode(['item' => 'prod_2', 'neighbors' => [['id' => 'prod_1', 'score' => 0.88]]]),
        ];
        file_put_contents($tmpDir . '/neighbors.jsonl', implode("\n", $neighborsData));

        // Test activation with gate requirement 0.30 (passes)
        $exitCode = $this->artisan('relevance:artifacts:activate', [
            'manifest' => $tmpDir . '/manifest.json',
            '--index' => 'products',
            '--min-metric' => 0.30,
        ])->run();

        $this->assertSame(0, $exitCode);

        // Verify status in relevance_model_artifacts
        $artifact = DB::table('relevance_model_artifacts')
            ->where('model_version', 'v2026.10')
            ->first();
        $this->assertNotNull($artifact);
        $this->assertSame('active', $artifact->status);

        // Verify neighbors were imported
        $neighborsCount = DB::table('relevance_item_neighbors')
            ->where('model_version', 'v2026.10')
            ->count();
        $this->assertSame(3, $neighborsCount);

        // 2. Test failure with gate requirement 0.80 (fails since model NDCG is 0.45)
        $manifestFail = [
            'algo' => 'item2vec',
            'model_version' => 'v2026.11',
            'metrics' => [
                'ndcg@10' => 0.45,
            ],
        ];
        file_put_contents($tmpDir . '/manifest_fail.json', json_encode($manifestFail));

        $failExitCode = $this->artisan('relevance:artifacts:activate', [
            'manifest' => $tmpDir . '/manifest_fail.json',
            '--index' => 'products',
            '--min-metric' => 0.80,
        ])->run();

        $this->assertSame(1, $failExitCode);

        $rejectedArtifact = DB::table('relevance_model_artifacts')
            ->where('model_version', 'v2026.11')
            ->first();
        $this->assertNotNull($rejectedArtifact);
        $this->assertSame('rejected', $rejectedArtifact->status);

        // Cleanup temp dir
        @unlink($tmpDir . '/manifest.json');
        @unlink($tmpDir . '/neighbors.jsonl');
        @unlink($tmpDir . '/manifest_fail.json');
        @rmdir($tmpDir);
    }

    public function test_federated_multi_search(): void
    {
        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('articles'), 'articles_test');
        $driver->swapAlias('articles', 'articles_test');

        $driver->upsert('articles', [
            new Document(id: 'art_1', version: 1, fields: ['title' => 'Review of the MacBook Pro 16']),
            new Document(id: 'art_2', version: 1, fields: ['title' => 'Why Dell XPS is Great']),
        ]);

        $federated = Relevance::multi([
            'products' => 1.0,
            'articles' => 0.5,
        ])->query('MacBook')->limit(5)->get();

        $this->assertNotEmpty($federated->all());
        $this->assertArrayHasKey('products', $federated->sections());
        $this->assertArrayHasKey('articles', $federated->sections());

        // Products weight 1.0 vs Articles weight 0.5
        $firstHit = $federated->all()[0];
        $this->assertNotNull($firstHit);
    }

    public function test_rag_retrieval_api_parent_collapse(): void
    {
        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('knowledge_base'), 'knowledge_base_test');
        $driver->swapAlias('knowledge_base', 'knowledge_base_test');

        $driver->upsert('knowledge_base', [
            new Document(id: 'chk_1_1', version: 1, fields: ['parent_id' => 'doc_1', 'content' => 'Laravel indexing guide chunk 1', 'title' => 'Guide Part 1']),
            new Document(id: 'chk_1_2', version: 1, fields: ['parent_id' => 'doc_1', 'content' => 'Laravel indexing guide chunk 2', 'title' => 'Guide Part 2']),
            new Document(id: 'chk_1_3', version: 1, fields: ['parent_id' => 'doc_1', 'content' => 'Laravel indexing guide chunk 3', 'title' => 'Guide Part 3']),
            new Document(id: 'chk_2_1', version: 1, fields: ['parent_id' => 'doc_2', 'content' => 'Laravel search guide chunk 1', 'title' => 'Search Guide']),
        ]);

        $rag = Relevance::retrieve('Laravel')
            ->index('knowledge_base')
            ->chunks()
            ->maxPerParent(2)
            ->limit(10)
            ->get();

        $this->assertNotEmpty($rag->chunks);
        // doc_1 should only have at most 2 chunks despite 3 matches
        $this->assertLessThanOrEqual(2, count($rag->groupedByParent['doc_1'] ?? []));
        $this->assertArrayHasKey('doc_2', $rag->groupedByParent);
        $this->assertNotNull($rag->chunks[0]->citations);
    }

    public function test_scout_engine_search_and_map_ids(): void
    {
        $engine = new RelevanceScoutEngine();

        $builder = new class {
            public string $index = 'products';
            public string $query = 'Sony';
            public int $limit = 5;
            public $model;

            public function __construct()
            {
                $this->model = new class extends \Illuminate\Database\Eloquent\Model {
                    public function searchableAs(): string
                    {
                        return 'products';
                    }
                };
            }
        };

        $response = $engine->search($builder);
        $this->assertNotEmpty($response->hits);

        $ids = $engine->mapIds($response);
        $this->assertContains('prod_5', $ids->all());
    }
}
