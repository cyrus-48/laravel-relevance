<?php

declare(strict_types=1);

namespace Relevance\Recommendations;

use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\PlacementDefinition;
use Relevance\Recommendations\Data\RecContext;
use Relevance\Recommendations\Data\RecResult;
use Relevance\Recommendations\Filters\FilterRegistry;
use Relevance\Recommendations\Generators\GeneratorRegistry;

class PlacementRunner
{
    public function __construct(
        private readonly PlacementRegistry $placements,
        private readonly GeneratorRegistry $generators,
        private readonly FilterRegistry $filters,
    ) {}

    public function run(string|PlacementDefinition $placementOrName, RecContext $ctx): RecResult
    {
        $start = hrtime(true);
        $timings = [];
        $degraded = [];

        $placement = is_string($placementOrName)
            ? $this->placements->get($placementOrName)
            : $placementOrName;

        $targetLimit = $ctx->limit > 0 ? $ctx->limit : $placement->limit;
        $pool = max($placement->pool, $targetLimit * 2);

        // 1. Candidate Generation
        $genStart = hrtime(true);
        /** @var array<string, list<Candidate>> $generatorLists */
        $generatorLists = [];

        foreach ($placement->generators as $genName => $weight) {
            $generator = $this->generators->get($genName);
            if ($generator === null) {
                $degraded[] = "generator_not_found:{$genName}";
                continue;
            }

            try {
                $candidates = $generator->generate($ctx, $pool, $placement->index);
                if (!empty($candidates)) {
                    $generatorLists[$genName] = $candidates;
                }
            } catch (\Throwable $e) {
                $degraded[] = "generator_failed:{$genName}";
            }
        }
        $timings['generation'] = (hrtime(true) - $genStart) / 1e6;

        // 2. Merge & Dedupe (RRF fusion across generator lists)
        $mergeStart = hrtime(true);
        $merged = $this->fuseGenerators($generatorLists, $placement->generators);
        $timings['fusion'] = (hrtime(true) - $mergeStart) / 1e6;

        // 3. Apply Filters
        $filterStart = hrtime(true);
        $filtered = $merged;
        foreach ($placement->filters as $filterName) {
            $filter = $this->filters->get($filterName);
            if ($filter !== null) {
                $filtered = $filter->apply($filtered, $ctx, $placement->index);
            }
        }
        $timings['filters'] = (hrtime(true) - $filterStart) / 1e6;

        // 4. Fallbacks if candidates are sparse
        if (count($filtered) < $targetLimit && !empty($placement->fallback)) {
            $fallbackStart = hrtime(true);
            $existingIds = array_flip(array_map(static fn (Candidate $c) => $c->id, $filtered));

            foreach ($placement->fallback as $fallbackGenName) {
                $fallbackGen = $this->generators->get($fallbackGenName);
                if ($fallbackGen === null) {
                    continue;
                }

                $needed = $targetLimit - count($filtered);
                $fallbackCandidates = $fallbackGen->generate($ctx, $needed * 2, $placement->index);

                // Filter fallbacks with existing IDs and placement filters
                $validFallbacks = array_values(array_filter(
                    $fallbackCandidates,
                    static fn (Candidate $c) => !isset($existingIds[$c->id])
                ));

                foreach ($placement->filters as $filterName) {
                    $filter = $this->filters->get($filterName);
                    if ($filter !== null) {
                        $validFallbacks = $filter->apply($validFallbacks, $ctx, $placement->index);
                    }
                }

                foreach ($validFallbacks as $c) {
                    $filtered[] = $c;
                    $existingIds[$c->id] = true;
                    if (count($filtered) >= $targetLimit) {
                        break 2;
                    }
                }
            }
            $timings['fallback'] = (hrtime(true) - $fallbackStart) / 1e6;
        }

        // 5. Exploration slots (if configured)
        $exploreSlots = (int) ($placement->explore['slots'] ?? 0);
        if ($exploreSlots > 0 && count($filtered) > $exploreSlots) {
            $filtered = $this->injectExplorationSlots($filtered, $exploreSlots);
        }

        // 6. Slice to limit
        $final = array_slice($filtered, 0, $targetLimit);

        $timings['total'] = (hrtime(true) - $start) / 1e6;

        return new RecResult(
            candidates: $final,
            placement: $placement->name,
            context: $ctx,
            timings: $timings,
            degraded: $degraded,
        );
    }

    /**
     * @param array<string, list<Candidate>> $lists
     * @param array<string, float> $weights
     * @return list<Candidate>
     */
    private function fuseGenerators(array $lists, array $weights): array
    {
        if (empty($lists)) {
            return [];
        }

        $k = 60;
        $scores = [];
        $reasons = [];
        $metadata = [];
        $primaryGenerator = [];

        foreach ($lists as $genName => $candidates) {
            $w = $weights[$genName] ?? 1.0;

            foreach ($candidates as $rank => $candidate) {
                $id = $candidate->id;
                $rrfScore = $w / ($k + $rank + 1);

                $scores[$id] = ($scores[$id] ?? 0.0) + $rrfScore;
                $reasons[$id] = array_merge($reasons[$id] ?? [], $candidate->reasons);
                $metadata[$id] = array_merge($metadata[$id] ?? [], $candidate->metadata);
                $primaryGenerator[$id] ??= $candidate->generator ?: $genName;
            }
        }

        arsort($scores);

        $fused = [];
        foreach ($scores as $id => $score) {
            $fused[] = new Candidate(
                id: (string) $id,
                score: (float) $score,
                reasons: array_values(array_unique($reasons[$id] ?? [])),
                generator: $primaryGenerator[$id] ?? '',
                metadata: $metadata[$id] ?? [],
            );
        }

        return $fused;
    }

    /**
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function injectExplorationSlots(array $candidates, int $slots): array
    {
        $count = count($candidates);
        if ($count <= $slots) {
            return $candidates;
        }

        // Tag the last $slots items as exploration items
        $result = $candidates;
        for ($i = $count - $slots; $i < $count; $i++) {
            $orig = $result[$i];
            $result[$i] = $orig->with($orig->score, ['explore']);
        }

        return array_values($result);
    }
}
