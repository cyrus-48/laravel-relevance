<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Data\Hit;
use Relevance\Data\SearchRequest;
use Relevance\Facades\Relevance;
use Relevance\Filters\Filter;
use Relevance\Filters\Range;
use Relevance\Search\Profiles\ProfileDefinition;
use Relevance\Search\Rules\ExactMatcher;
use Relevance\Search\Rules\RegexMatcher;
use Relevance\Search\Rules\Rule;
use Relevance\Search\Rules\RuleEngine;
use Relevance\Search\Rules\RuleRepository;
use Relevance\Tests\TestCase;

class QueryRulesTest extends TestCase
{
    public function test_pin_and_bury_actions(): void
    {
        $repo = new RuleRepository();
        $engine = new RuleEngine($repo);

        $repo->pin('wireless earphones', ids: ['300'], positions: [1], index: 'products');
        $repo->bury('wireless earphones', ids: ['100'], index: 'products');

        $initialHits = [
            new Hit(id: '100', score: 10.0),
            new Hit(id: '200', score: 8.0),
            new Hit(id: '300', score: 6.0),
            new Hit(id: '400', score: 4.0),
        ];

        $result = $engine->evaluatePost($initialHits, 'wireless earphones', 'products');

        $finalIds = array_map(fn (Hit $h) => $h->id, $result->hits);

        // '300' is pinned at position 1.
        // '100' is buried at the end.
        $this->assertSame('300', $finalIds[0]);
        $this->assertSame('100', $finalIds[count($finalIds) - 1]);
        $this->assertContains('pin_wireless earphones', $result->rulesApplied);
        $this->assertContains('bury_wireless earphones', $result->rulesApplied);
    }

    public function test_exclude_action(): void
    {
        $repo = new RuleRepository();
        $engine = new RuleEngine($repo);

        $repo->exclude('sneakers', ids: ['bad_item'], index: 'products');

        $hits = [
            new Hit(id: 'item_1', score: 5.0),
            new Hit(id: 'bad_item', score: 4.0),
            new Hit(id: 'item_2', score: 3.0),
        ];

        $result = $engine->evaluatePost($hits, 'sneakers', 'products');
        $ids = array_map(fn (Hit $h) => $h->id, $result->hits);

        $this->assertNotContains('bad_item', $ids);
        $this->assertCount(2, $result->hits);
    }

    public function test_pre_retrieval_redirect_and_inject_filter(): void
    {
        $repo = new RuleRepository();
        $engine = new RuleEngine($repo);

        $repo->addRule(new Rule(
            name: 'help_redirect',
            index: 'products',
            priority: 10,
            matcher: new ExactMatcher('returns'),
            actions: [
                ['type' => 'redirect', 'url' => '/help/returns'],
            ],
        ));

        $repo->addRule(new Rule(
            name: 'cheap_filter',
            index: 'products',
            priority: 10,
            matcher: new RegexMatcher('/^cheap\s+/i'),
            actions: [
                ['type' => 'inject_filter', 'field' => 'price', 'operator' => '<', 'value' => 50],
                ['type' => 'rewrite', 'query' => 'shoes'],
            ],
        ));

        // Test 1: Redirect
        $req1 = new SearchRequest(index: 'products', query: 'returns');
        $profile = new ProfileDefinition('default', 'products');
        $eval1 = $engine->evaluatePre($req1, $profile);
        $this->assertSame('/help/returns', $eval1->redirectUrl);
        $this->assertContains('help_redirect', $eval1->rulesApplied);

        // Test 2: Injected Filter & Query Rewrite
        $req2 = new SearchRequest(index: 'products', query: 'cheap shoes');
        $eval2 = $engine->evaluatePre($req2, $profile);
        $this->assertSame('shoes', $eval2->request->query);
        $this->assertInstanceOf(Range::class, $eval2->request->filter);
        $this->assertContains('cheap_filter', $eval2->rulesApplied);
    }
}
