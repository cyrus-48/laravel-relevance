<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\TextRetriever;
use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Facades\Relevance;
use Relevance\Search\SearchExplainResult;
use Relevance\Tests\TestCase;

class SearchExplainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        // Seed some data in memory driver
        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('products'), 'products_test');
        $driver->swapAlias('products', 'products_test');

        $driver->upsert('products', [
            new Document(id: '1', version: 1, fields: ['title' => 'Red Running Sneakers', 'price' => 120]),
            new Document(id: '2', version: 1, fields: ['title' => 'Blue Sneakers', 'price' => 90]),
        ]);
    }

    public function test_explain_returns_scoring_and_rule_breakdown(): void
    {
        Relevance::synonyms()->oneWay('shoes', 'sneakers', index: 'products');
        Relevance::rules()->pin('running shoes', ids: ['2'], positions: [1], index: 'products');

        /** @var SearchExplainResult $explain */
        $explain = Relevance::index('products')
            ->query('running shoes')
            ->explain();

        $this->assertSame('running shoes', $explain->originalQuery);
        $this->assertSame('running shoes', $explain->normalizedQuery);
        $this->assertArrayHasKey('shoes', $explain->synonymExpansions);
        $this->assertContains('sneakers', $explain->synonymExpansions['shoes']);
        $this->assertContains('pin_running shoes', $explain->rulesApplied);

        $this->assertNotEmpty($explain->finalHits);
        $this->assertSame('2', $explain->finalHits[0]->id);

        $array = $explain->toArray();
        $this->assertSame('running shoes', $array['original_query']);
        $this->assertArrayHasKey('hits', $array);
        $this->assertArrayHasKey('timings', $array);
    }

    public function test_cli_explain_command(): void
    {
        $this->artisan('relevance:explain', [
            'query' => 'Sneakers',
            '--index' => 'products',
        ])
            ->expectsOutputToContain('Relevance Explain')
            ->expectsOutputToContain('Ranked Hits')
            ->assertExitCode(0);
    }
}
