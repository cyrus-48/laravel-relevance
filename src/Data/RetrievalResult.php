<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class RetrievalResult
{
    /**
     * @param list<Hit> $hits
     * @param array<string, array<string, int>> $facets
     */
    public function __construct(
        public array $hits,
        public int $total,
        public bool $estimatedTotal = false,
        public array $facets = [],
    ) {}
}
