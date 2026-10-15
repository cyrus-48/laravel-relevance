<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

final readonly class ExactMatcher implements RuleMatcher
{
    public function __construct(private string $value) {}

    public function matches(string $query): bool
    {
        return mb_strtolower(trim($query)) === mb_strtolower(trim($this->value));
    }
}
