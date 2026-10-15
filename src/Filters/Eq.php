<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class Eq extends Filter
{
    public function __construct(
        public string $field,
        public mixed $value,
    ) {}
}
