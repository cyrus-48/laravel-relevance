<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class In extends Filter
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {}
}
