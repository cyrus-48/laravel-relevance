<?php

declare(strict_types=1);

namespace Relevance\Search\Diversify;

use Relevance\Data\Hit;

final class FieldCollapser
{
    /**
     * Collapse hits by field values (e.g. max 2 per brand).
     *
     * @param list<Hit> $hits
     * @param array<string, int> $maxPerField field => max_count
     * @return list<Hit>
     */
    public function collapse(array $hits, array $maxPerField): array
    {
        if (empty($hits) || empty($maxPerField)) {
            return $hits;
        }

        $counts = []; // [field => [val => count]]
        $kept = [];

        foreach ($hits as $hit) {
            $allowed = true;

            foreach ($maxPerField as $field => $maxCount) {
                $val = $hit->fields[$field] ?? null;
                if ($val === null) {
                    continue;
                }

                $key = is_scalar($val) ? (string) $val : serialize($val);
                $currentCount = $counts[$field][$key] ?? 0;

                if ($currentCount >= $maxCount) {
                    $allowed = false;
                    break;
                }
            }

            if ($allowed) {
                foreach ($maxPerField as $field => $maxCount) {
                    $val = $hit->fields[$field] ?? null;
                    if ($val !== null) {
                        $key = is_scalar($val) ? (string) $val : serialize($val);
                        $counts[$field][$key] = ($counts[$field][$key] ?? 0) + 1;
                    }
                }
                $kept[] = $hit;
            }
        }

        return $kept;
    }
}
