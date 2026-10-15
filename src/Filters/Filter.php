<?php

declare(strict_types=1);

namespace Relevance\Filters;

abstract readonly class Filter
{
    public static function eq(string $field, mixed $value): Eq
    {
        return new Eq($field, $value);
    }

    /**
     * @param list<mixed> $values
     */
    public static function in(string $field, array $values): In
    {
        return new In($field, $values);
    }

    public static function range(
        string $field,
        mixed $gte = null,
        mixed $lte = null,
        mixed $gt = null,
        mixed $lt = null,
    ): Range {
        return new Range($field, $gte, $lte, $gt, $lt);
    }

    public static function exists(string $field): Exists
    {
        return new Exists($field);
    }

    public static function geoRadius(string $field, float $lat, float $lng, float $radiusMeters): GeoRadius
    {
        return new GeoRadius($field, $lat, $lng, $radiusMeters);
    }

    public static function all(Filter ...$filters): AndFilter
    {
        return new AndFilter(array_values($filters));
    }

    public static function any(Filter ...$filters): OrFilter
    {
        return new OrFilter(array_values($filters));
    }

    public static function not(Filter $filter): NotFilter
    {
        return new NotFilter($filter);
    }
}
