<?php

declare(strict_types=1);

namespace Relevance\Data;

use Relevance\Filters\Filter;

final readonly class SearchRequest
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
        public array $sort = [],
        public int $limit = 20,
        public ?string $cursor = null,
        public array $facets = [],
        public SearchMode $mode = SearchMode::Hybrid,
        public ?float $semanticRatio = null,
        public ?string $profile = null,
        public SearchContext $context = new SearchContext(),
        public array $options = [],
    ) {}

    public function withFilter(Filter $filter): self
    {
        return new self(
            index: $this->index,
            query: $this->query,
            filter: $this->filter ? Filter::all($this->filter, $filter) : $filter,
            sort: $this->sort,
            limit: $this->limit,
            cursor: $this->cursor,
            facets: $this->facets,
            mode: $this->mode,
            semanticRatio: $this->semanticRatio,
            profile: $this->profile,
            context: $this->context,
            options: $this->options,
        );
    }

    public function withContext(SearchContext $context): self
    {
        return new self(
            index: $this->index,
            query: $this->query,
            filter: $this->filter,
            sort: $this->sort,
            limit: $this->limit,
            cursor: $this->cursor,
            facets: $this->facets,
            mode: $this->mode,
            semanticRatio: $this->semanticRatio,
            profile: $this->profile,
            context: $context,
            options: $this->options,
        );
    }
}
