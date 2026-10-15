<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class IndexStats
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public int $documentCount,
        public int $sizeBytes = 0,
        public array $extra = [],
    ) {}
}
