<?php

declare(strict_types=1);

namespace Relevance\Indexing;

/**
 * Outcome of backfilling a new index generation from its source.
 */
final readonly class BackfillReport
{
    public function __construct(
        public int $documents,
        public int $embedded,
        public int $embeddingFailures,
        public bool $expectsVectors,
    ) {}

    /**
     * A generation is safe to activate automatically only if every document that
     * should carry a vector actually got one. Activating a vector-less generation
     * silently degrades hybrid/semantic search to text-only.
     */
    public function isComplete(): bool
    {
        if (!$this->expectsVectors) {
            return true;
        }

        return $this->embeddingFailures === 0 && $this->embedded === $this->documents;
    }
}
