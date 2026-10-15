<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

final readonly class ContainsMatcher implements RuleMatcher
{
    public function __construct(private string $value) {}

    public function matches(string $query): bool
    {
        return str_contains(mb_strtolower($query), mb_strtolower($this->value));
    }
}
