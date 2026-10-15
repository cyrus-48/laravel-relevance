<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use Relevance\Embeddings\TokenBudgetGuard;
use Relevance\Tests\TestCase;

class TokenBudgetGuardTest extends TestCase
{
    public function test_budget_spend_and_exhaustion(): void
    {
        $guard = new TokenBudgetGuard(dailyTokenBudget: 100, onExhaustedAction: 'defer');

        $this->assertFalse($guard->isExhausted());
        $this->assertTrue($guard->canSpend(50));

        $guard->spend(60);
        $this->assertSame(60, $guard->currentSpend());
        $this->assertFalse($guard->isExhausted());
        $this->assertFalse($guard->canSpend(50));

        $guard->spend(50);
        $this->assertTrue($guard->isExhausted());
        $this->assertSame('defer', $guard->onExhausted());
    }
}
