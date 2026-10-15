<?php

declare(strict_types=1);

namespace Relevance\Search\Federated;

use Relevance\Data\Hit;
use Relevance\Data\SearchResponse;

final readonly class FederatedSearchResponse
{
    /**
     * @param list<Hit> $hits
     * @param array<string, SearchResponse> $byIndex
     * @param array<string, float> $timings
     */
    public function __construct(
        public array $hits,
        public array $byIndex,
        public int $total,
        public array $timings = [],
    ) {}

    /**
     * @return list<Hit>
     */
    public function all(): array
    {
        return $this->hits;
    }

    /**
     * @return array<string, SearchResponse>
     */
    public function sections(): array
    {
        return $this->byIndex;
    }
}
