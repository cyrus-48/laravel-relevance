<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class UpsertReport
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $indexedCount,
        public int $deletedCount = 0,
        public float $durationMs = 0.0,
        public array $errors = [],
    ) {}

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }
}
