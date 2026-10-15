<?php

declare(strict_types=1);

namespace Relevance\Drivers\Memory;

use Relevance\Contracts\FilterCompiler;
use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Filters\AndFilter;
use Relevance\Filters\Eq;
use Relevance\Filters\Exists;
use Relevance\Filters\Filter;
use Relevance\Filters\GeoRadius;
use Relevance\Filters\In;
use Relevance\Filters\NotFilter;
use Relevance\Filters\OrFilter;
use Relevance\Filters\Range;

final readonly class MemoryFilterCompiler implements FilterCompiler
{
    /**
     * Compiles the filter into a callable that accepts a Document and returns bool.
     *
     * @return callable(Document): bool
     */
    public function compile(Filter $filter, ?IndexSchema $schema = null): callable
    {
        return fn (Document $doc): bool => $this->matches($filter, $doc);
    }

    public function matches(Filter $filter, Document $doc): bool
    {
        return match (true) {
            $filter instanceof Eq => $this->matchesEq($filter, $doc),
            $filter instanceof In => $this->matchesIn($filter, $doc),
            $filter instanceof Range => $this->matchesRange($filter, $doc),
            $filter instanceof Exists => $this->matchesExists($filter, $doc),
            $filter instanceof GeoRadius => $this->matchesGeoRadius($filter, $doc),
            $filter instanceof AndFilter => $this->matchesAnd($filter, $doc),
            $filter instanceof OrFilter => $this->matchesOr($filter, $doc),
            $filter instanceof NotFilter => !$this->matches($filter->filter, $doc),
            default => true,
        };
    }

    private function matchesEq(Eq $filter, Document $doc): bool
    {
        $val = $this->extractField($filter->field, $doc);
        if (is_array($val)) {
            return in_array($filter->value, $val, false);
        }

        return $val == $filter->value;
    }

    private function matchesIn(In $filter, Document $doc): bool
    {
        $val = $this->extractField($filter->field, $doc);
        if (is_array($val)) {
            return !empty(array_intersect($val, $filter->values));
        }

        return in_array($val, $filter->values, false);
    }

    private function matchesRange(Range $filter, Document $doc): bool
    {
        $val = $this->extractField($filter->field, $doc);
        if ($val === null) {
            return false;
        }

        if ($filter->gte !== null && $val < $filter->gte) {
            return false;
        }
        if ($filter->lte !== null && $val > $filter->lte) {
            return false;
        }
        if ($filter->gt !== null && $val <= $filter->gt) {
            return false;
        }
        if ($filter->lt !== null && $val >= $filter->lt) {
            return false;
        }

        return true;
    }

    private function matchesExists(Exists $filter, Document $doc): bool
    {
        return $this->extractField($filter->field, $doc) !== null;
    }

    private function matchesGeoRadius(GeoRadius $filter, Document $doc): bool
    {
        $location = $this->extractField($filter->field, $doc);
        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            return false;
        }

        $distance = $this->haversineMeters(
            $filter->lat,
            $filter->lng,
            (float) $location['lat'],
            (float) $location['lng']
        );

        return $distance <= $filter->radiusMeters;
    }

    private function matchesAnd(AndFilter $filter, Document $doc): bool
    {
        foreach ($filter->filters as $sub) {
            if (!$this->matches($sub, $doc)) {
                return false;
            }
        }

        return true;
    }

    private function matchesOr(OrFilter $filter, Document $doc): bool
    {
        if (empty($filter->filters)) {
            return true;
        }

        foreach ($filter->filters as $sub) {
            if ($this->matches($sub, $doc)) {
                return true;
            }
        }

        return false;
    }

    private function extractField(string $field, Document $doc): mixed
    {
        if ($field === 'id') {
            return $doc->id;
        }
        if ($field === 'tenant_id' || $field === 'tenant') {
            return $doc->tenant ?? ($doc->fields['tenant_id'] ?? ($doc->fields['tenant'] ?? null));
        }
        if ($field === 'acl') {
            return $doc->acl;
        }

        return $doc->fields[$field] ?? null;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
