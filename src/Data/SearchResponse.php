<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class SearchResponse
{
    /**
     * @param list<Hit> $hits
     * @param array<string, array<string, int>> $facets
     * @param array<string, float> $timings stage_name => duration_ms
     * @param list<string> $degraded list of degraded stage names or reasons
     * @param list<string> $appliedRules list of applied rule identifiers
     */
    public function __construct(
        public array $hits,
        public int $total,
        public bool $estimatedTotal = false,
        public array $facets = [],
        public ?string $nextCursor = null,
        public string $searchId = '',
        public array $timings = [],
        public array $degraded = [],
        public array $appliedRules = [],
        public ?string $profile = null,
        public ?string $variant = null,
        public ?string $redirectUrl = null,
    ) {}

    public function isDegraded(): bool
    {
        return !empty($this->degraded);
    }
}
