<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;

interface HybridRetriever extends Driver
{
    /**
     * @param list<float> $embedding
     */
    public function hybrid(RetrievalRequest $request, array $embedding, float $semanticRatio): RetrievalResult;
}
