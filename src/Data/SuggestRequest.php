<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class SuggestRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $index,
        public string $query,
        public int $limit = 5,
        public SearchContext $context = new SearchContext(),
        public array $options = [],
    ) {}
}
