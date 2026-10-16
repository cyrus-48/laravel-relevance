<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Exceptions\MissingTenantScopeException;
use Relevance\Facades\Relevance;
use Relevance\Recommendations\Data\RecContext;
use Relevance\Recommendations\Generators\ContentSimilarGenerator;
use Relevance\Tests\TestCase;

class ContentSimilarGeneratorTest extends TestCase
{
    private const INDEX = 'similar_items';

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Indexer $driver */
        $driver = Relevance::driver();
        $driver->upsert(self::INDEX, [
            new Document(id: 'a', fields: ['title' => 'a', 'embedding' => [1.0, 0.0, 0.0]], tenant: 't1'),
            new Document(id: 'b', fields: ['title' => 'b', 'embedding' => [0.9, 0.1, 0.0]], tenant: 't1'),
            new Document(id: 'd', fields: ['title' => 'd', 'embedding' => [0.5, 0.5, 0.0]], tenant: 't1'),
            new Document(id: 'c', fields: ['title' => 'c', 'embedding' => [0.0, 1.0, 0.0]], tenant: 't1'),
            new Document(id: 'x', fields: ['title' => 'x', 'embedding' => [1.0, 0.0, 0.0]], tenant: 't2'),
            new Document(id: 'novec', fields: ['title' => 'no vector'], tenant: 't1'),
        ]);
    }

    public function test_returns_nearest_neighbours_by_the_seeds_own_vector_excluding_the_seed(): void
    {
        $ids = array_map(
            static fn ($c) => $c->id,
            (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'a', tenantId: 't1'), 10, self::INDEX),
        );

        // b is closest, d is next; c is orthogonal (no similarity); seed excluded; other tenant excluded.
        $this->assertSame(['b', 'd'], $ids);
    }

    public function test_candidates_carry_a_reason_and_generator_name(): void
    {
        $candidates = (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'a', tenantId: 't1'), 10, self::INDEX);

        $this->assertSame('content_similar', $candidates[0]->generator);
        $this->assertSame(['similar_content:a'], $candidates[0]->reasons);
        $this->assertGreaterThan($candidates[1]->score, $candidates[0]->score);
    }

    public function test_never_leaks_another_tenants_items(): void
    {
        $ids = array_map(
            static fn ($c) => $c->id,
            (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'a', tenantId: 't1'), 10, self::INDEX),
        );

        $this->assertNotContains('x', $ids);

        // A seed that belongs to a different tenant is not visible at all.
        $this->assertSame([], (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'x', tenantId: 't1'), 10, self::INDEX));
    }

    public function test_seed_without_a_vector_or_unknown_seed_yields_no_candidates(): void
    {
        $generator = new ContentSimilarGenerator();

        $this->assertSame([], $generator->generate(new RecContext(seedItem: 'novec', tenantId: 't1'), 10, self::INDEX));
        $this->assertSame([], $generator->generate(new RecContext(seedItem: 'missing', tenantId: 't1'), 10, self::INDEX));
        $this->assertSame([], $generator->generate(new RecContext(seedItem: null), 10, self::INDEX));
    }

    public function test_does_not_text_match_the_seed_id(): void
    {
        // Regression: the placeholder implementation searched for the seed *id* as query text.
        // An item whose title merely contains that string must not be returned.
        /** @var Indexer $driver */
        $driver = Relevance::driver();
        $driver->upsert(self::INDEX, [
            new Document(id: 'trap', fields: ['title' => 'about a', 'embedding' => [0.0, 0.0, 1.0]], tenant: 't1'),
        ]);

        $ids = array_map(
            static fn ($c) => $c->id,
            (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'a', tenantId: 't1'), 10, self::INDEX),
        );

        $this->assertNotContains('trap', $ids);
    }

    public function test_requires_a_tenant_when_tenancy_is_enforced(): void
    {
        config(['relevance.tenancy.required' => true]);

        $this->expectException(MissingTenantScopeException::class);

        (new ContentSimilarGenerator())->generate(new RecContext(seedItem: 'a'), 10, self::INDEX);
    }
}
