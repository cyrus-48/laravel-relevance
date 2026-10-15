<?php

declare(strict_types=1);

namespace Relevance\Testing;

use Relevance\Contracts\Reranker;
use Relevance\Data\Hit;
use Relevance\Data\RerankOptions;
use RuntimeException;

class FakeReranker implements Reranker
{
    private bool $shouldFail = false;
    private bool $shouldTimeout = false;
    /** @var callable(string, list<Hit>): list<Hit>|null */
    private $customRerank = null;

    public function fail(): self
    {
        $this->shouldFail = true;
        return $this;
    }

    public function timeout(): self
    {
        $this->shouldTimeout = true;
        return $this;
    }

    /**
     * @param callable(string, list<Hit>): list<Hit> $callback
     */
    public function withCallback(callable $callback): self
    {
        $this->customRerank = $callback;
        return $this;
    }

    public function rerank(string $query, array $hits, RerankOptions $options): array
    {
        if ($this->shouldTimeout) {
            throw new RuntimeException("Reranker request timed out.");
        }

        if ($this->shouldFail) {
            throw new RuntimeException("Reranker service error.");
        }

        if ($options->deadline !== null && $options->deadline->exceeded()) {
            throw new RuntimeException("Reranker deadline exceeded.");
        }

        if ($this->customRerank !== null) {
            return ($this->customRerank)($query, $hits);
        }

        // Default fake behavior: slightly adjust scores based on hit ID or reverse top-R
        $topR = min($options->topR, count($hits));
        $toRerank = array_slice($hits, 0, $topR);
        $remainder = array_slice($hits, $topR);

        // Reverse top-R to simulate cross-encoder changing rank order
        $reranked = array_reverse($toRerank);
        $result = [];

        foreach ($reranked as $rank => $hit) {
            $newScore = 1.0 - ($rank * 0.05);
            $result[] = $hit->with(
                score: $newScore,
                stage: 'rerank',
                detail: ['model' => $options->model ?? 'fake-rerank', 'new_rank' => $rank + 1]
            );
        }

        return array_merge($result, $remainder);
    }
}
