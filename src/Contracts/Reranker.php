<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\Hit;
use Relevance\Data\RerankOptions;

interface Reranker
{
    /**
     * @param list<Hit> $hits
     * @return list<Hit>
     */
    public function rerank(string $query, array $hits, RerankOptions $options): array;
}
