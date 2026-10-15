<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Relevance\Concerns\Searchable;
use Relevance\Facades\Relevance;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\PlacementDefinition;
use Relevance\Recommendations\Data\RecContext;
use Relevance\Recommendations\Training\ItemKnnTrainer;
use Relevance\Tests\TestCase;

class RecProductModel extends Model
{
    use Searchable;

    protected $table = 'rec_test_products';
    protected $guarded = [];
}

class RecommendationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        DbSchema::create('rec_test_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    public function test_planted_cluster_test_recovers_neighbors(): void
    {
        // Cluster 1: Item 10 & Item 11 co-occur across 5 users
        // Cluster 2: Item 20 & Item 21 co-occur across 5 users
        for ($u = 1; $u <= 5; $u++) {
            Relevance::track("user_{$u}")->purchase('10', index: 'products');
            Relevance::track("user_{$u}")->purchase('11', index: 'products');
        }

        for ($u = 6; $u <= 10; $u++) {
            Relevance::track("user_{$u}")->purchase('20', index: 'products');
            Relevance::track("user_{$u}")->purchase('21', index: 'products');
        }

        $trainer = new ItemKnnTrainer();
        $report = $trainer->train(index: 'products', minSupport: 2, shrinkage: 1.0);

        $this->assertGreaterThan(0, $report['neighbors_computed']);

        // Assert item 10 has neighbor 11
        $neighborOf10 = DB::table('relevance_item_neighbors')
            ->where('index', 'products')
            ->where('item_id', '10')
            ->first();

        $this->assertNotNull($neighborOf10);
        $this->assertSame('11', $neighborOf10->neighbor_id);

        // Assert item 20 has neighbor 21
        $neighborOf20 = DB::table('relevance_item_neighbors')
            ->where('index', 'products')
            ->where('item_id', '20')
            ->first();

        $this->assertNotNull($neighborOf20);
        $this->assertSame('21', $neighborOf20->neighbor_id);
    }

    public function test_already_purchased_filter_excludes_purchased_items(): void
    {
        // User 1 purchased item 100
        Relevance::track('user_test_1')->purchase('100', index: 'products');

        // Create neighbors for seed item 50: [100, 200]
        DB::table('relevance_item_neighbors')->insert([
            [
                'index' => 'products',
                'algo' => 'item_knn',
                'item_id' => '50',
                'neighbor_id' => '100', // Already purchased
                'score' => 0.9,
                'model_version' => 'v1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'index' => 'products',
                'algo' => 'item_knn',
                'item_id' => '50',
                'neighbor_id' => '200', // Not purchased
                'score' => 0.8,
                'model_version' => 'v1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $customPlacement = new PlacementDefinition(
            name: 'test_product_similar',
            index: 'products',
            generators: ['item_neighbors' => 1.0],
            filters: ['already_purchased'],
            fallback: [],
            limit: 10,
        );

        $result = Relevance::placement($customPlacement)
            ->for('user_test_1')
            ->seed('50')
            ->get();

        $ids = $result->ids();

        // 100 must be filtered out because user_test_1 already purchased it
        $this->assertNotContains('100', $ids);
        $this->assertContains('200', $ids);
    }

    public function test_exploration_slots_inject_explore_tag(): void
    {
        // Seed item neighbors for item 50: 1, 2, 3, 4
        foreach (['1', '2', '3', '4'] as $idx => $id) {
            DB::table('relevance_item_neighbors')->insert([
                'index' => 'products',
                'algo' => 'item_knn',
                'item_id' => '50',
                'neighbor_id' => $id,
                'score' => 1.0 - ($idx * 0.1),
                'model_version' => 'v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $placement = new PlacementDefinition(
            name: 'test_explore',
            index: 'products',
            generators: ['item_neighbors' => 1.0],
            filters: [],
            explore: ['slots' => 1],
            limit: 4,
        );

        $result = Relevance::placement($placement)
            ->seed('50')
            ->get();

        $this->assertCount(4, $result->candidates);

        // The exploration slot (last candidate) should have 'explore' in reasons
        $lastCandidate = $result->candidates[3];
        $this->assertContains('explore', $lastCandidate->reasons);
    }

    public function test_eloquent_recommendation_shortcuts(): void
    {
        $p1 = RecProductModel::create(['id' => 1, 'name' => 'Running Shoes', 'price' => 100]);
        $p2 = RecProductModel::create(['id' => 2, 'name' => 'Sport Socks', 'price' => 15]);

        DB::table('relevance_item_neighbors')->insert([
            'index' => 'default',
            'algo' => 'item_knn',
            'item_id' => '1',
            'neighbor_id' => '2',
            'score' => 0.85,
            'model_version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. $product->similar()
        $similar = $p1->similar(limit: 5);
        $this->assertNotEmpty($similar);
        $this->assertSame(2, $similar->first()?->id);

        // 2. RecProductModel::recommendFor($user)
        $recs = RecProductModel::recommendFor('user_42', limit: 5)->get();
        $this->assertInstanceOf(\Relevance\Recommendations\Data\RecResult::class, $recs);

        // 3. RecProductModel::alsoInteracted($p1, 'purchase')
        $also = RecProductModel::alsoInteracted($p1, 'purchase', limit: 5)->get();
        $this->assertSame('1', $also->context->seedItem);

        // 4. RecProductModel::trending()
        $trending = RecProductModel::trending(window: '24h', limit: 5)->get();
        $this->assertSame('24h', $trending->context->context['window'] ?? null);
    }

    public function test_cli_rec_train_command(): void
    {
        Relevance::track('user_a')->purchase('item_a', index: 'default');
        Relevance::track('user_a')->purchase('item_b', index: 'default');
        Relevance::track('user_b')->purchase('item_a', index: 'default');
        Relevance::track('user_b')->purchase('item_b', index: 'default');

        $this->artisan('relevance:rec:train', [
            '--index' => 'default',
            '--min-support' => 2,
            '--shrinkage' => 1.0,
        ])
            ->expectsOutputToContain('Training recommendations')
            ->expectsOutputToContain('Recommendation training completed successfully')
            ->assertExitCode(0);
    }
}
