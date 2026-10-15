<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Relevance\Data\Hit;
use Relevance\Experiments\Bucketer;
use Relevance\Experiments\TeamDraftInterleaving;

class BucketerAndInterleavingTest extends TestCase
{
    public function test_bucketer_is_deterministic(): void
    {
        $bucketer = new Bucketer();

        $b1 = $bucketer->bucket('user_42', salt: 'test_exp');
        $b2 = $bucketer->bucket('user_42', salt: 'test_exp');

        $this->assertSame($b1, $b2);
        $this->assertGreaterThanOrEqual(0, $b1);
        $this->assertLessThan(10000, $b1);

        $variants = ['control' => 'profile_a', 'treatment' => 'profile_b'];
        $split = [50, 50];

        $assign1 = $bucketer->assign('user_42', $variants, $split, salt: 'test_exp');
        $assign2 = $bucketer->assign('user_42', $variants, $split, salt: 'test_exp');

        $this->assertSame($assign1, $assign2);
    }

    public function test_bucketer_split_distribution(): void
    {
        $bucketer = new Bucketer();
        $variants = ['control' => 'profile_a', 'treatment' => 'profile_b'];
        $split = [50, 50];

        $counts = ['control' => 0, 'treatment' => 0];

        for ($i = 0; $i < 1000; $i++) {
            $assignment = $bucketer->assign("user_{$i}", $variants, $split, salt: 'dist_test');
            $counts[$assignment['variant']]++;
        }

        // Each variant should get roughly 50% (allow tolerance for pseudo-random hash)
        $this->assertGreaterThan(400, $counts['control']);
        $this->assertGreaterThan(400, $counts['treatment']);
    }

    public function test_team_draft_interleaving(): void
    {
        $interleaver = new TeamDraftInterleaving();

        $listA = [
            new Hit(id: '1', score: 10.0),
            new Hit(id: '2', score: 9.0),
            new Hit(id: '3', score: 8.0),
        ];

        $listB = [
            new Hit(id: '2', score: 10.0), // Duplicate item
            new Hit(id: '4', score: 9.0),
            new Hit(id: '5', score: 8.0),
        ];

        $interleaved = $interleaver->interleave($listA, $listB, 'control', 'treatment', limit: 4);

        $this->assertCount(4, $interleaved);

        $ids = array_map(fn (Hit $h) => $h->id, $interleaved);

        // No duplicates
        $this->assertSame(count($ids), count(array_unique($ids)));

        // Both teams contributed
        $teams = array_map(fn (Hit $h) => $h->breakdown->stages['interleave']['team'] ?? null, $interleaved);
        $this->assertContains('control', $teams);
        $this->assertContains('treatment', $teams);
    }
}
