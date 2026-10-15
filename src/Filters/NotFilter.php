<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class NotFilter extends Filter
{
    public function __construct(
        public Filter $filter,
    ) {}
}
