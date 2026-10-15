<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Relevance\Data\Hit;
use Relevance\Search\Fusion\ReciprocalRankFusion;

class ReciprocalRankFusionTest extends TestCase
{
    public function test_fuses_two_retrievers_correctly(): void
    {
        $fuser = new ReciprocalRankFusion(k: 60);

        $textHits = [
            new Hit(id: 'doc1', score: 10.0),
            new Hit(id: 'doc2', score: 8.0),
        ];

        $vectorHits = [
            new Hit(id: 'doc2', score: 0.95),
            new Hit(id: 'doc3', score: 0.85),
        ];

        $fused = $fuser->fuse([
            'text'   => $textHits,
            'vector' => $vectorHits,
        ]);

        $this->assertCount(3, $fused);
        // doc2 appears in both lists (rank 2 in text, rank 1 in vector), so should have highest score:
        // doc2 = 1/(60+2) + 1/(60+1) = 1/62 + 1/61 ≈ 0.016129 + 0.016393 = 0.032522
        // doc1 = 1/(60+1) = 1/61 ≈ 0.016393
        // doc3 = 1/(60+2) = 1/62 ≈ 0.016129
        $this->assertSame('doc2', $fused[0]->id);
        $this->assertSame('doc1', $fused[1]->id);
        $this->assertSame('doc3', $fused[2]->id);

        $this->assertArrayHasKey('fusion', $fused[0]->breakdown->toArray());
    }

    public function test_rrf_is_invariant_to_monotone_score_transforms(): void
    {
        $fuser = new ReciprocalRankFusion(k: 60);

        $listA1 = [new Hit('1', 10.0), new Hit('2', 5.0), new Hit('3', 1.0)];
        $listB1 = [new Hit('2', 0.9), new Hit('3', 0.8), new Hit('1', 0.1)];

        // Monotone transform on scores (e.g. multiply by 100, add 50, log, etc.)
        $listA2 = [new Hit('1', 1000.0), new Hit('2', 500.0), new Hit('3', 100.0)];
        $listB2 = [new Hit('2', 90.0), new Hit('3', 80.0), new Hit('1', 10.0)];

        $fused1 = $fuser->fuse(['a' => $listA1, 'b' => $listB1]);
        $fused2 = $fuser->fuse(['a' => $listA2, 'b' => $listB2]);

        $ids1 = array_map(fn ($h) => $h->id, $fused1);
        $ids2 = array_map(fn ($h) => $h->id, $fused2);

        $this->assertSame($ids1, $ids2);
    }

    public function test_weights_adjust_contribution(): void
    {
        $fuser = new ReciprocalRankFusion(k: 60);

        $textHits = [new Hit(id: 'doc_text', score: 10.0)];
        $vectorHits = [new Hit(id: 'doc_vector', score: 0.99)];

        // Heavily weight vector
        $fused = $fuser->fuse([
            'text'   => $textHits,
            'vector' => $vectorHits,
        ], [
            'text'   => 0.1,
            'vector' => 0.9,
        ]);

        $this->assertSame('doc_vector', $fused[0]->id);
        $this->assertSame('doc_text', $fused[1]->id);
    }
}
