<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Search\Understanding\QueryNormalizer;
use Relevance\Search\Understanding\SynonymExpander;
use Relevance\Search\Understanding\SynonymRepository;
use Relevance\Tests\TestCase;

class SynonymsTest extends TestCase
{
    public function test_query_normalizer(): void
    {
        $normalizer = new QueryNormalizer();

        $this->assertSame('wireless headphones', $normalizer->normalize('   Wireless   HEADPHONES   '));
    }

    public function test_one_way_and_multi_way_synonym_expansion(): void
    {
        $repo = new SynonymRepository();
        $repo->oneWay('tv', 'television', index: 'catalog');
        $repo->multiWay(['laptop', 'notebook', 'macbook'], index: 'catalog');

        $expander = new SynonymExpander($repo);

        // 1. One-way test
        $expandedOneWay = $expander->expand('smart tv', 'catalog');
        $this->assertStringContainsString('television', $expandedOneWay);
        $this->assertStringContainsString('tv', $expandedOneWay);
        $this->assertStringContainsString('smart', $expandedOneWay);

        // 2. Multi-way test
        $expandedMultiWay = $expander->expand('buy a laptop', 'catalog');
        $this->assertStringContainsString('notebook', $expandedMultiWay);
        $this->assertStringContainsString('macbook', $expandedMultiWay);

        // 3. Explainability expansions map
        $expansions = $expander->getExpansions('smart tv laptop', 'catalog');
        $this->assertArrayHasKey('tv', $expansions);
        $this->assertContains('television', $expansions['tv']);
        $this->assertArrayHasKey('laptop', $expansions);
        $this->assertContains('notebook', $expansions['laptop']);
        $this->assertContains('macbook', $expansions['laptop']);
    }
}
