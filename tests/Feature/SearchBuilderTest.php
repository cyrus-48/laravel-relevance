<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema as DbSchema;
use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Events\StaleHitDetected;
use Relevance\Facades\Relevance;
use Relevance\Schema\IndexRegistry;
use Relevance\Tests\TestCase;

class SearchBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        DbSchema::create('test_products_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 8, 2);
            $table->timestamps();
        });

        /** @var IndexRegistry $registry */
        $registry = app(IndexRegistry::class);
        $registry->registerModel(ProductModel::class, ProductModelIndex::class);

        // Preload driver with alias
        $driver = Relevance::driver();
        /** @var \Relevance\Contracts\IndexAdmin $driver */
        $driver->create((new ProductModelIndex())->compiledSchema(), 'test_products_v1');
        $driver->swapAlias('test_products', 'test_products_v1');
    }

    public function test_fluent_where_filters_and_sorting(): void
    {
        $p1 = ProductModel::create(['name' => 'Running Shoes Pro', 'price' => 150.00]);
        $p2 = ProductModel::create(['name' => 'Running Shoes Lite', 'price' => 75.00]);
        $p3 = ProductModel::create(['name' => 'Hiking Boots', 'price' => 180.00]);

        $driver = Relevance::driver();
        $this->assertInstanceOf(Indexer::class, $driver);

        $driver->upsert('test_products', [
            new Document(id: (string) $p1->id, version: 1, fields: ['name' => $p1->name, 'price' => 150.00]),
            new Document(id: (string) $p2->id, version: 1, fields: ['name' => $p2->name, 'price' => 75.00]),
            new Document(id: (string) $p3->id, version: 1, fields: ['name' => $p3->name, 'price' => 180.00]),
        ]);

        // Filter price <= 100
        $res = ProductModel::relevance('Shoes')
            ->where('price', '<=', 100)
            ->get();

        $this->assertCount(1, $res->hits);
        $this->assertSame((string) $p2->id, $res->hits[0]->id);

        // Sort by price desc
        $sortedRes = ProductModel::relevance('Shoes')
            ->sortBy('price', 'desc')
            ->get();

        $this->assertCount(2, $sortedRes->hits);
        $this->assertSame((string) $p1->id, $sortedRes->hits[0]->id);
        $this->assertSame((string) $p2->id, $sortedRes->hits[1]->id);
    }

    public function test_hydration_returns_eloquent_models_in_rank_order(): void
    {
        $p1 = ProductModel::create(['name' => 'Alpha Gadget', 'price' => 10.00]);
        $p2 = ProductModel::create(['name' => 'Beta Gadget', 'price' => 20.00]);

        $driver = Relevance::driver();
        /** @var Indexer $driver */
        $driver->upsert('test_products', [
            new Document(id: (string) $p2->id, version: 1, fields: ['name' => $p2->name, 'price' => 20.00]),
            new Document(id: (string) $p1->id, version: 1, fields: ['name' => $p1->name, 'price' => 10.00]),
        ]);

        $models = ProductModel::relevance('Gadget')
            ->sortBy('price', 'desc')
            ->models();

        $this->assertCount(2, $models);
        $this->assertSame($p2->id, $models[0]->id);
        $this->assertSame($p1->id, $models[1]->id);
    }

    public function test_stale_hit_drops_missing_model_and_emits_event(): void
    {
        Event::fake([StaleHitDetected::class]);

        $p1 = ProductModel::create(['name' => 'Valid Gadget', 'price' => 10.00]);

        // Document with ID 999 exists in index, but does not exist in DB
        $driver = Relevance::driver();
        /** @var Indexer $driver */
        $driver->upsert('test_products', [
            new Document(id: (string) $p1->id, version: 1, fields: ['name' => $p1->name, 'price' => 10.00]),
            new Document(id: '999', version: 1, fields: ['name' => 'Ghost Gadget', 'price' => 99.00]),
        ]);

        $models = ProductModel::relevance('Gadget')->models();

        // 999 should be dropped!
        $this->assertCount(1, $models);
        $this->assertSame($p1->id, $models[0]->id);

        Event::assertDispatched(StaleHitDetected::class, function (StaleHitDetected $e) {
            return $e->index === 'test_products' && $e->docId === '999';
        });
    }

    public function test_pagination_returns_length_aware_paginator(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = ProductModel::create(['name' => "Item Number {$i}", 'price' => $i * 10]);
            /** @var Indexer $driver */
            $driver = Relevance::driver();
            $driver->upsert('test_products', [
                new Document(id: (string) $p->id, version: 1, fields: ['name' => $p->name, 'price' => $i * 10]),
            ]);
        }

        $paginator = ProductModel::relevance('Item')->paginate(perPage: 2, page: 2);

        $this->assertSame(5, $paginator->total());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertCount(2, $paginator->items());
    }
}
