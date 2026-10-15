<?php

declare(strict_types=1);

namespace Relevance\Search\Rag;

final readonly class RagRetrievalResult
{
    /**
     * @param list<ChunkHit> $chunks
     * @param array<string, list<ChunkHit>> $groupedByParent
     * @param array<string, float> $timings
     */
    public function __construct(
        public array $chunks,
        public array $groupedByParent,
        public int $total,
        public array $timings = [],
    ) {}
}
