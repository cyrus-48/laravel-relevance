<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class OrFilter extends Filter
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public array $filters,
    ) {}
}
