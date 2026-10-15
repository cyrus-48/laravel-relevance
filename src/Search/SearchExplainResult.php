<?php

declare(strict_types=1);

namespace Relevance\Search;

use Relevance\Data\Hit;
use Relevance\Filters\Filter;

final readonly class SearchExplainResult
{
    /**
     * @param array<string, list<string>> $synonymExpansions
     * @param list<string> $rulesApplied
     * @param list<Hit> $retrievedHits
     * @param list<Hit> $finalHits
     * @param array<string, float> $timings
     * @param list<string> $degraded
     */
    public function __construct(
        public string $originalQuery,
        public string $normalizedQuery,
        public array $synonymExpansions,
        public ?Filter $finalFilter,
        public string $profileName,
        public array $rulesApplied,
        public ?string $redirectUrl,
        public array $retrievedHits,
        public array $finalHits,
        public array $timings = [],
        public array $degraded = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_query' => $this->originalQuery,
            'normalized_query' => $this->normalizedQuery,
            'synonym_expansions' => $this->synonymExpansions,
            'filter' => $this->finalFilter !== null ? get_class($this->finalFilter) : null,
            'profile' => $this->profileName,
            'rules_applied' => $this->rulesApplied,
            'redirect_url' => $this->redirectUrl,
            'retrieved_count' => count($this->retrievedHits),
            'final_count' => count($this->finalHits),
            'hits' => array_map(static fn (Hit $h) => [
                'id' => $h->id,
                'score' => $h->score,
                'breakdown' => $h->breakdown->stages,
            ], $this->finalHits),
            'timings' => $this->timings,
            'degraded' => $this->degraded,
        ];
    }
}
