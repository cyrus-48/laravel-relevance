<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class Hit
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $highlights
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $fields = [],
        public array $highlights = [],
        public ScoreBreakdown $breakdown = new ScoreBreakdown(),
        public ?string $source = null,
    ) {}

    public function with(float $score, ?string $stage = null, mixed $detail = null): self
    {
        $breakdown = $this->breakdown;
        if ($stage !== null) {
            $breakdown = $breakdown->with($stage, $detail ?? ['score' => $score]);
        }

        return new self(
            id: $this->id,
            score: $score,
            fields: $this->fields,
            highlights: $this->highlights,
            breakdown: $breakdown,
            source: $this->source,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            id: $this->id,
            score: $this->score,
            fields: array_merge($this->fields, $fields),
            highlights: $this->highlights,
            breakdown: $this->breakdown,
            source: $this->source,
        );
    }
}
