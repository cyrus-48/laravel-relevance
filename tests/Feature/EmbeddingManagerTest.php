<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\EmbeddingProvider;
use Relevance\Data\EmbedKind;
use Relevance\Embeddings\EmbeddingCache;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Embeddings\TokenBudgetGuard;
use Relevance\Tests\TestCase;
use RuntimeException;

class CountingFakeProvider implements EmbeddingProvider
{
    public int $calls = 0;

    public function fingerprint(): string
    {
        return 'counting:fake:4:v1';
    }

    public function dimensions(): int
    {
        return 4;
    }

    public function embed(array $texts, EmbedKind $kind = EmbedKind::Document): array
    {
        $this->calls += count($texts);
        return array_map(fn () => [0.1, 0.2, 0.3, 0.4], $texts);
    }
}

class EmbeddingManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
    }

    public function test_caching_prevents_duplicate_provider_calls(): void
    {
        $cache = new EmbeddingCache();
        $guard = new TokenBudgetGuard(dailyTokenBudget: 10000);
        $provider = new CountingFakeProvider();

        $manager = new EmbeddingManager($cache, $guard, 'test');
        $manager->set('test', $provider);

        // First call computes embeddings
        $vec1 = $manager->embed('Same repeated text query');
        $this->assertSame(1, $provider->calls);
        $this->assertCount(4, $vec1);

        // Second call should hit database cache, not increment provider calls!
        $vec2 = $manager->embed('Same repeated text query');
        $this->assertSame(1, $provider->calls);
        $this->assertSame($vec1, $vec2);
    }

    public function test_budget_exhaustion_throws_exception(): void
    {
        $cache = new EmbeddingCache();
        $guard = new TokenBudgetGuard(dailyTokenBudget: 10);
        $guard->spend(10); // Exhaust budget

        $manager = new EmbeddingManager($cache, $guard, 'fake');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding daily token budget exhausted');

        $manager->embed('Any fresh uncached text');
    }
}
