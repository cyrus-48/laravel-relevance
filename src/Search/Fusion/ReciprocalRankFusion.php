<?php

declare(strict_types=1);

namespace Relevance\Search\Fusion;

use Relevance\Contracts\Fuser;
use Relevance\Data\Hit;

final readonly class ReciprocalRankFusion implements Fuser
{
    public function __construct(
        private int $k = 60,
    ) {}

    /**
     * @param array<string, list<Hit>> $lists retriever name => ranked hits
     * @param array<string, float> $weights retriever name => weight (default 1.0)
     * @return list<Hit>
     */
    public function fuse(array $lists, array $weights = []): array
    {
        $scores = [];
        $proto = [];
        $parts = [];

        foreach ($lists as $name => $hits) {
            $w = $weights[$name] ?? 1.0;
            foreach ($hits as $i => $hit) {
                $c = $w / ($this->k + $i + 1);
                $scores[$hit->id] = ($scores[$hit->id] ?? 0.0) + $c;
                $proto[$hit->id] ??= $hit;
                $parts[$hit->id][$name] = [
                    'rank' => $i + 1,
                    'raw'  => $hit->score,
                    'rrf'  => $c,
                ];
            }
        }

        $ids = array_keys($scores);
        usort(
            $ids,
            static fn (string $a, string $b) => ($scores[$b] <=> $scores[$a]) ?: strcmp($a, $b)
        );

        return array_map(
            static fn (string $id): Hit => $proto[$id]->with($scores[$id], 'fusion', $parts[$id]),
            $ids
        );
    }
}
