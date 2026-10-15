<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class ScoreBreakdown
{
    /**
     * @param array<string, mixed> $stages stage_name => breakdown data (e.g. ranks, weights, contributions)
     */
    public function __construct(
        public array $stages = [],
    ) {}

    public function with(string $stage, mixed $detail): self
    {
        $stages = $this->stages;
        $stages[$stage] = $detail;

        return new self($stages);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->stages;
    }
}
