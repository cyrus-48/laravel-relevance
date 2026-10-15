<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use Relevance\Embeddings\EmbeddingCache;
use Relevance\Tests\TestCase;

class EmbeddingCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
    }

    public function test_cache_put_and_get(): void
    {
        $cache = new EmbeddingCache();
        $fingerprint = 'test:provider:v1';
        $input = 'Hello world of search';
        $vector = [0.1, 0.2, 0.3, 0.4];

        $this->assertNull($cache->get($fingerprint, $input));

        $cache->put($fingerprint, $input, $vector, tokens: 4);

        $cached = $cache->get($fingerprint, $input);
        $this->assertSame($vector, $cached);
    }

    public function test_cache_batch_get_and_put(): void
    {
        $cache = new EmbeddingCache();
        $fingerprint = 'test:provider:v1';

        $items = [
            'red shoes'  => [1.0, 0.0],
            'blue shoes' => [0.0, 1.0],
        ];

        $cache->putMany($fingerprint, $items, tokensPerItem: 2);

        $results = $cache->getMany($fingerprint, ['red shoes', 'blue shoes', 'green shoes']);

        $this->assertCount(2, $results);
        $this->assertSame([1.0, 0.0], $results['red shoes']);
        $this->assertSame([0.0, 1.0], $results['blue shoes']);
        $this->assertArrayNotHasKey('green shoes', $results);
    }
}
