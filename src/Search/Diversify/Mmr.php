<?php

declare(strict_types=1);

namespace Relevance\Search\Diversify;

use Relevance\Data\Hit;

final readonly class Mmr
{
    public function __construct(
        private float $lambda = 0.7,
    ) {}

    /**
     * @param list<Hit> $hits scores should be normalized; $sim(a,b) returns similarity in [0,1]
     * @param callable(Hit, Hit): float $sim
     * @return list<Hit>
     */
    public function diversify(array $hits, int $k, callable $sim): array
    {
        $selected = [];
        $pool = $hits;

        while (count($selected) < $k && !empty($pool)) {
            $bestIdx = null;
            $bestScore = -INF;

            foreach ($pool as $idx => $cand) {
                $redundancy = !empty($selected)
                    ? max(array_map(static fn (Hit $s): float => (float) $sim($cand, $s), $selected))
                    : 0.0;

                $mmr = ($this->lambda * $cand->score) - ((1.0 - $this->lambda) * $redundancy);
                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $idx;
                }
            }

            if ($bestIdx === null) {
                break;
            }

            $selected[] = $pool[$bestIdx];
            unset($pool[$bestIdx]);
        }

        return $selected;
    }
}
