<?php

declare(strict_types=1);

namespace Relevance\Search\Understanding;

final class QueryNormalizer
{
    public function normalize(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }
}
