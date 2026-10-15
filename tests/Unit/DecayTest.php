<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Relevance\Support\Decay;

class DecayTest extends TestCase
{
    public function test_decay_at_exact_half_life(): void
    {
        $now = Carbon::parse('2026-10-15 12:00:00');
        $halfLifeDays = 30.0;

        // Exactly 30 days ago
        $at = $now->copy()->subDays(30);

        $factor = Decay::factor($at, $now, $halfLifeDays);
        $this->assertEqualsWithDelta(0.5, $factor, 0.0001);

        // Same time -> factor 1.0
        $factorNow = Decay::factor($now, $now, $halfLifeDays);
        $this->assertEqualsWithDelta(1.0, $factorNow, 0.0001);

        // Two half-lives (60 days) -> factor 0.25
        $at60 = $now->copy()->subDays(60);
        $factor60 = Decay::factor($at60, $now, $halfLifeDays);
        $this->assertEqualsWithDelta(0.25, $factor60, 0.0001);
    }
}
