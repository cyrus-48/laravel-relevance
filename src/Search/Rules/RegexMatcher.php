<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

final readonly class RegexMatcher implements RuleMatcher
{
    public function __construct(private string $pattern) {}

    public function matches(string $query): bool
    {
        return @preg_match($this->pattern, $query) === 1;
    }
}
