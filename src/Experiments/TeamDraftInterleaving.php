<?php

declare(strict_types=1);

namespace Relevance\Experiments;

use Relevance\Data\Hit;

final class TeamDraftInterleaving
{
    /**
     * Interleave two ranked lists using Team-Draft Interleaving.
     *
     * @param list<Hit> $listA
     * @param list<Hit> $listB
     * @param string $teamAName
     * @param string $teamBName
     * @return list<Hit>
     */
    public function interleave(
        array $listA,
        array $listB,
        string $teamAName = 'A',
        string $teamBName = 'B',
        int $limit = 20,
    ): array {
        $interleaved = [];
        $selectedIds = [];
        $teamACount = 0;
        $teamBCount = 0;

        $idxA = 0;
        $idxB = 0;
        $nA = count($listA);
        $nB = count($listB);

        $flip = 0;

        while (count($interleaved) < $limit && ($idxA < $nA || $idxB < $nB)) {
            // Determine which team picks next
            if ($teamACount < $teamBCount) {
                $priority = 'A';
            } elseif ($teamBCount < $teamACount) {
                $priority = 'B';
            } else {
                // Toss coin / alternate deterministically
                $priority = ($flip++ % 2 === 0) ? 'A' : 'B';
            }

            $firstTeam = $priority;
            $secondTeam = $priority === 'A' ? 'B' : 'A';

            $picked = $firstTeam === 'A'
                ? $this->pickNextItem($listA, $idxA, $selectedIds, $teamAName)
                : $this->pickNextItem($listB, $idxB, $selectedIds, $teamBName);

            if ($picked !== null) {
                $interleaved[] = $picked;
                $selectedIds[$picked->id] = true;
                if ($firstTeam === 'A') {
                    $teamACount++;
                } else {
                    $teamBCount++;
                }
            }

            if (count($interleaved) >= $limit) {
                break;
            }

            $pickedSecond = $secondTeam === 'A'
                ? $this->pickNextItem($listA, $idxA, $selectedIds, $teamAName)
                : $this->pickNextItem($listB, $idxB, $selectedIds, $teamBName);

            if ($pickedSecond !== null) {
                $interleaved[] = $pickedSecond;
                $selectedIds[$pickedSecond->id] = true;
                if ($secondTeam === 'A') {
                    $teamACount++;
                } else {
                    $teamBCount++;
                }
            }
        }

        return $interleaved;
    }

    /**
     * @param list<Hit> $list
     * @param int $cursor
     * @param array<string, bool> $selectedIds
     */
    private function pickNextItem(array $list, int &$cursor, array $selectedIds, string $teamName): ?Hit
    {
        $n = count($list);
        while ($cursor < $n) {
            $candidate = $list[$cursor++];
            if (!isset($selectedIds[$candidate->id])) {
                return $candidate->with($candidate->score, 'interleave', ['team' => $teamName]);
            }
        }

        return null;
    }
}
