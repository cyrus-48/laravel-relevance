<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\SuggestRequest;
use Relevance\Data\SuggestResult;

interface Suggester extends Driver
{
    public function suggest(SuggestRequest $request): SuggestResult;
}
