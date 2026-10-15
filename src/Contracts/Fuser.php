<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\Hit;

interface Fuser
{
    /**
     * @param array<string, list<Hit>> $lists retriever name => ranked hits
     * @param array<string, float> $weights retriever name => weight
     * @return list<Hit>
     */
    public function fuse(array $lists, array $weights = []): array;
}
