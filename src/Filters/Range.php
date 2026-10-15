<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class Range extends Filter
{
    public function __construct(
        public string $field,
        public mixed $gte = null,
        public mixed $lte = null,
        public mixed $gt = null,
        public mixed $lt = null,
    ) {}
}
