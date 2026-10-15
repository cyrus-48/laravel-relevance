<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Data;

final readonly class RecResult
{
    /**
     * @param list<Candidate> $candidates
     * @param array<string, float> $timings
     * @param list<string> $degraded
     */
    public function __construct(
        public array $candidates,
        public string $placement,
        public RecContext $context,
        public array $timings = [],
        public array $degraded = [],
    ) {}

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (Candidate $c) => $c->id, $this->candidates);
    }
}
