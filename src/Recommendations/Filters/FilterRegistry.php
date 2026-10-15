<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Filters;

class FilterRegistry
{
    /** @var array<string, CandidateFilter> */
    private array $filters = [];

    public function __construct()
    {
        $this->register(new AlreadyPurchasedFilter());
        $this->register(new DismissedFilter());
    }

    public function register(CandidateFilter $filter): self
    {
        $this->filters[$filter->name()] = $filter;
        return $this;
    }

    public function get(string $name): ?CandidateFilter
    {
        return $this->filters[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->filters[$name]);
    }
}
