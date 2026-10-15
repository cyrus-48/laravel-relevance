<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class SuggestResult
{
    /**
     * @param list<string> $queries
     * @param list<Hit> $documents
     */
    public function __construct(
        public array $queries = [],
        public array $documents = [],
    ) {}
}
