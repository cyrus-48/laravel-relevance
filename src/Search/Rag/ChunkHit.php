<?php

declare(strict_types=1);

namespace Relevance\Search\Rag;

final readonly class ChunkHit
{
    /**
     * @param array<string, mixed> $citations
     */
    public function __construct(
        public string $id,
        public string $parentId,
        public string $content,
        public float $score,
        public array $citations = [],
    ) {}
}
