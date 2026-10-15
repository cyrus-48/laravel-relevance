<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class IndexHandle
{
    public function __construct(
        public string $alias,
        public string $physicalName,
        public int $generation,
        public ?string $embeddingFingerprint = null,
    ) {}
}
