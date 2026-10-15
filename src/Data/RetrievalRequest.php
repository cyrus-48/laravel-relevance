<?php

declare(strict_types=1);

namespace Relevance\Data;

use Relevance\Filters\Filter;

final readonly class RetrievalRequest
{
    /**
     * @param array<string, 'asc'|'desc'> $sort
     * @param list<string> $facets
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $index,
        public string $query = '',
        public ?Filter $filter = null,
        public int $limit = 20,
        public int $offset = 0,
        public array $sort = [],
        public array $facets = [],
        public SearchContext $context = new SearchContext(),
        public array $options = [],
    ) {}
}
