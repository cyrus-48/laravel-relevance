<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;

interface VectorRetriever extends Driver
{
    /**
     * @param list<float> $embedding
     */
    public function vector(RetrievalRequest $request, array $embedding): RetrievalResult;
}
