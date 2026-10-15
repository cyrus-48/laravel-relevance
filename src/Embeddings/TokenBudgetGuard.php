<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Illuminate\Support\Facades\Cache;

class TokenBudgetGuard
{
    public function __construct(
        private readonly int $dailyTokenBudget = 20_000_000,
        private readonly string $onExhaustedAction = 'defer',
    ) {}

    public function isExhausted(): bool
    {
        return $this->currentSpend() >= $this->dailyTokenBudget;
    }

    public function canSpend(int $estimatedTokens = 1): bool
    {
        return ($this->currentSpend() + $estimatedTokens) <= $this->dailyTokenBudget;
    }

    public function spend(int $tokens): void
    {
        $key = $this->cacheKey();
        if (!Cache::has($key)) {
            Cache::put($key, $tokens, now()->endOfDay());
        } else {
            Cache::increment($key, $tokens);
        }
    }

    public function currentSpend(): int
    {
        return (int) Cache::get($this->cacheKey(), 0);
    }

    public function onExhausted(): string
    {
        return $this->onExhaustedAction;
    }

    private function cacheKey(): string
    {
        return 'relevance:tokens:' . date('Y-m-d');
    }
}
