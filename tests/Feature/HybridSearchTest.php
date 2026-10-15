<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\EmbeddingProvider;
use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Data\EmbedKind;
use Relevance\Data\IndexSchema;
use Relevance\Data\SearchMode;
use Relevance\Data\SearchRequest;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;
use RuntimeException;

class FailingEmbeddingProvider implements EmbeddingProvider
{
    public function fingerprint(): string
    {
        return 'failing:v1';
    }

    public function dimensions(): int
    {
        return 4;
    }

    public function embed(array $texts, EmbedKind $kind = EmbedKind::Document): array
    {
        throw new RuntimeException('Embedding API service unavailable (503)');
    }
}

class HybridSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $driver = Relevance::driver();
        /** @var \Relevance\Contracts\IndexAdmin $driver */
        $driver->create(new IndexSchema('products'), 'products_v1');
        $driver->swapAlias('products', 'products_v1');

        /** @var Indexer $driver */
        $driver->upsert('products', [
            new Document(
                id: '1',
                version: 1,
                fields: [
                    'title'     => 'Apple iPhone 15 Pro',
                    'embedding' => [0.9, 0.1, 0.0, 0.0],
                ],
            ),
            new Document(
                id: '2',
                version: 1,
                fields: [
                    'title'     => 'Samsung Galaxy S24 Smartphone',
                    'embedding' => [0.85, 0.15, 0.0, 0.0],
                ],
            ),
            new Document(
                id: '3',
                version: 1,
                fields: [
                    'title'     => 'Fruit Apple Basket Organic',
                    'embedding' => [0.0, 0.0, 0.9, 0.1],
                ],
            ),
        ]);
    }

    public function test_hybrid_search_fuses_text_and_vector(): void
    {
        // When searching "Apple smartphone":
        // Doc 1 has exact text 'Apple' and strong vector match.
        // Doc 2 has text 'Smartphone' and strong vector match.
        // Doc 3 has text 'Apple' but poor vector match.
        $req = new SearchRequest(
            index: 'products',
            query: 'Apple smartphone',
            mode: SearchMode::Hybrid,
            semanticRatio: 0.5,
        );

        $response = Relevance::search($req);

        $this->assertNotEmpty($response->hits);
        $this->assertFalse($response->isDegraded());
        $this->assertArrayHasKey('embedding', $response->timings);

        // Doc 1 matches both text and vector, so it should rank first
        $this->assertSame('1', $response->hits[0]->id);
        $this->assertArrayHasKey('fusion', $response->hits[0]->breakdown->toArray());
    }

    public function test_soft_degradation_falls_back_to_text_when_embeddings_fail(): void
    {
        // Replace active embedding provider with a failing provider
        /** @var EmbeddingManager $manager */
        $manager = app(EmbeddingManager::class);
        $manager->set('fake', new FailingEmbeddingProvider());

        $req = new SearchRequest(
            index: 'products',
            query: 'iPhone',
            mode: SearchMode::Hybrid,
        );

        $response = Relevance::search($req);

        // Search must NOT crash! It degrades soft to text-only mode
        $this->assertTrue($response->isDegraded());
        $this->assertContains('embedding', $response->degraded);
        $this->assertCount(1, $response->hits);
        $this->assertSame('1', $response->hits[0]->id);
    }
}
