<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

interface RuleMatcher
{
    public function matches(string $query): bool;
}
