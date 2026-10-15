<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class Exists extends Filter
{
    public function __construct(
        public string $field,
    ) {}
}
