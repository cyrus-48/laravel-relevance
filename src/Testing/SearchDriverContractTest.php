<?php

declare(strict_types=1);

namespace Relevance\Testing;

use Relevance\Contracts\Driver;
use Relevance\Contracts\IndexAdmin;
use Relevance\Contracts\Indexer;
use Relevance\Contracts\TextRetriever;
use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Data\RetrievalRequest;
use Relevance\Data\SearchContext;
use Relevance\Filters\Filter;
use Relevance\Tests\TestCase;

abstract class SearchDriverContractTest extends TestCase
{
    /**
     * @return Driver&IndexAdmin&Indexer&TextRetriever
     */
    abstract protected function createDriver(): Driver;

    public function test_upsert_then_text_search_finds_document(): void
    {
        $driver = $this->createDriver();
        $this->assertInstanceOf(IndexAdmin::class, $driver);
        $this->assertInstanceOf(Indexer::class, $driver);
        $this->assertInstanceOf(TextRetriever::class, $driver);

        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        $driver->upsert('products', [
            new Document(
                id: '1',
                version: 1,
                fields: ['title' => 'Wireless Noise Canceling Headphones', 'brand' => 'Sony'],
            ),
            new Document(
                id: '2',
                version: 1,
                fields: ['title' => 'Coffee Mug', 'brand' => 'Acme'],
            ),
        ]);

        $res = $driver->text(new RetrievalRequest(index: 'products', query: 'headphones'));

        $this->assertCount(1, $res->hits);
        $this->assertSame('1', $res->hits[0]->id);
        $this->assertSame('text', $res->hits[0]->source);
    }

    public function test_filter_is_applied_and_filters_out_non_matching_documents(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        $driver->upsert('products', [
            new Document(
                id: '1',
                version: 1,
                fields: ['title' => 'Red Running Shoes', 'price' => 120, 'category' => 'footwear'],
            ),
            new Document(
                id: '2',
                version: 1,
                fields: ['title' => 'Blue Running Shoes', 'price' => 80, 'category' => 'footwear'],
            ),
        ]);

        $res = $driver->text(new RetrievalRequest(
            index: 'products',
            query: 'shoes',
            filter: Filter::range('price', lte: 100),
        ));

        $this->assertCount(1, $res->hits);
        $this->assertSame('2', $res->hits[0]->id);
    }

    public function test_delete_removes_from_results(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        $driver->upsert('products', [
            new Document(id: '1', version: 1, fields: ['title' => 'Book One']),
            new Document(id: '2', version: 1, fields: ['title' => 'Book Two']),
        ]);

        $driver->delete('products', ['1']);

        $res = $driver->text(new RetrievalRequest(index: 'products', query: 'Book'));
        $this->assertCount(1, $res->hits);
        $this->assertSame('2', $res->hits[0]->id);
    }

    public function test_tenant_scope_isolation(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        $driver->upsert('products', [
            new Document(id: '1', version: 1, fields: ['title' => 'Laptop'], tenant: 'tenant_a'),
            new Document(id: '2', version: 1, fields: ['title' => 'Laptop'], tenant: 'tenant_b'),
        ]);

        $resA = $driver->text(new RetrievalRequest(
            index: 'products',
            query: 'Laptop',
            context: new SearchContext(tenant: 'tenant_a'),
        ));

        $this->assertCount(1, $resA->hits);
        $this->assertSame('1', $resA->hits[0]->id);

        $resB = $driver->text(new RetrievalRequest(
            index: 'products',
            query: 'Laptop',
            context: new SearchContext(tenant: 'tenant_b'),
        ));

        $this->assertCount(1, $resB->hits);
        $this->assertSame('2', $resB->hits[0]->id);
    }

    public function test_alias_swap_is_atomic_for_readers(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('products'), 'products_g1');
        $driver->create(new IndexSchema('products'), 'products_g2');

        $driver->swapAlias('products', 'products_g1');
        $driver->upsert('products_g1', [
            new Document(id: '1', version: 1, fields: ['title' => 'Old Version Article']),
        ]);
        $driver->upsert('products_g2', [
            new Document(id: '2', version: 1, fields: ['title' => 'New Version Article']),
        ]);

        $res1 = $driver->text(new RetrievalRequest(index: 'products', query: 'Article'));
        $this->assertSame('1', $res1->hits[0]->id);

        $driver->swapAlias('products', 'products_g2');

        $res2 = $driver->text(new RetrievalRequest(index: 'products', query: 'Article'));
        $this->assertSame('2', $res2->hits[0]->id);
    }

    public function test_external_versioning_prevents_stale_overwrites(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        // Initial write with version 5
        $driver->upsert('products', [
            new Document(id: '1', version: 5, fields: ['title' => 'Original newer title']),
        ]);

        // Attempt stale write with version 3
        $driver->upsert('products', [
            new Document(id: '1', version: 3, fields: ['title' => 'Stale older title']),
        ]);

        $res = $driver->text(new RetrievalRequest(index: 'products', query: 'title'));
        $this->assertCount(1, $res->hits);
        $this->assertSame('Original newer title', $res->hits[0]->fields['title']);
    }

    public function test_stable_ordering_on_ties(): void
    {
        $driver = $this->createDriver();
        $driver->create(new IndexSchema('items'), 'items_v1');
        $driver->swapAlias('items', 'items_v1');

        $driver->upsert('items', [
            new Document(id: 'b', version: 1, fields: ['title' => 'Same Score Item']),
            new Document(id: 'a', version: 1, fields: ['title' => 'Same Score Item']),
            new Document(id: 'c', version: 1, fields: ['title' => 'Same Score Item']),
        ]);

        $res = $driver->text(new RetrievalRequest(index: 'items', query: 'Same Score Item'));
        $ids = array_map(fn ($hit) => $hit->id, $res->hits);

        // Ties should be broken deterministically by ID asc
        $this->assertSame(['a', 'b', 'c'], $ids);
    }
}
