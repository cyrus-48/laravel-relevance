<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Data;

final readonly class Candidate
{
    /**
     * @param list<string> $reasons
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $reasons = [],
        public string $generator = '',
        public array $metadata = [],
    ) {}

    /**
     * @param list<string> $extraReasons
     */
    public function with(float $newScore, array $extraReasons = []): self
    {
        return new self(
            id: $this->id,
            score: $newScore,
            reasons: array_values(array_unique(array_merge($this->reasons, $extraReasons))),
            generator: $this->generator,
            metadata: $this->metadata,
        );
    }
}
