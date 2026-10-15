<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Relevance\Data\Hit;
use Relevance\Search\Diversify\Mmr;

class MmrTest extends TestCase
{
    public function test_mmr_with_lambda_one_preserves_input_order(): void
    {
        $mmr = new Mmr(lambda: 1.0);

        $hits = [
            new Hit(id: '1', score: 0.9),
            new Hit(id: '2', score: 0.8),
            new Hit(id: '3', score: 0.7),
        ];

        $diversified = $mmr->diversify($hits, 3, fn () => 1.0);
        $ids = array_map(fn ($h) => $h->id, $diversified);

        $this->assertSame(['1', '2', '3'], $ids);
    }

    public function test_mmr_penalizes_similar_candidates(): void
    {
        $mmr = new Mmr(lambda: 0.5);

        // doc1 and doc2 are almost identical (similarity = 0.99)
        // doc3 is different from doc1 (similarity = 0.1) but slightly lower initial score
        $hits = [
            new Hit(id: '1', score: 1.0),
            new Hit(id: '2', score: 0.95),
            new Hit(id: '3', score: 0.85),
        ];

        $simMap = [
            '1:2' => 0.99,
            '2:1' => 0.99,
            '1:3' => 0.1,
            '3:1' => 0.1,
            '2:3' => 0.1,
            '3:2' => 0.1,
        ];

        $sim = fn (Hit $a, Hit $b) => $simMap["{$a->id}:{$b->id}"] ?? 0.0;

        $diversified = $mmr->diversify($hits, 3, $sim);
        $ids = array_map(fn ($h) => $h->id, $diversified);

        // doc1 is chosen first. For second choice:
        // doc2 MMR = 0.5 * 0.95 - 0.5 * 0.99 = 0.475 - 0.495 = -0.02
        // doc3 MMR = 0.5 * 0.85 - 0.5 * 0.10 = 0.425 - 0.050 = 0.375
        // doc3 should be chosen before doc2!
        $this->assertSame(['1', '3', '2'], $ids);
    }
}
