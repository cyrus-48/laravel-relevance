<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;

interface TextRetriever extends Driver
{
    public function text(RetrievalRequest $request): RetrievalResult;
}
