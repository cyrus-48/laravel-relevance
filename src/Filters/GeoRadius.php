<?php

declare(strict_types=1);

namespace Relevance\Filters;

final readonly class GeoRadius extends Filter
{
    public function __construct(
        public string $field,
        public float $lat,
        public float $lng,
        public float $radiusMeters,
    ) {}
}
