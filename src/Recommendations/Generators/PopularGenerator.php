<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class PopularGenerator implements CandidateGenerator
{
    public function name(): string
    {
        return 'popular';
    }

    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array
    {
        try {
            // 1. Try precomputed item stats
            if (Schema::hasTable('relevance_item_stats')) {
                $rows = DB::table('relevance_item_stats')
                    ->where('index', $index)
                    ->orderByDesc('popularity_score')
                    ->limit($limit)
                    ->get();

                if ($rows->isNotEmpty()) {
                    $candidates = [];
                    foreach ($rows as $row) {
                        $candidates[] = new Candidate(
                            id: (string) $row->item_id,
                            score: (float) $row->popularity_score,
                            reasons: ['popular'],
                            generator: $this->name(),
                        );
                    }
                    return $candidates;
                }
            }

            // 2. Fallback to interactions table
            if (Schema::hasTable('relevance_interactions')) {
                $rows = DB::table('relevance_interactions')
                    ->select('item_id', DB::raw('SUM(COALESCE(weight, 1.0)) as total_weight'))
                    ->where('index', $index)
                    ->groupBy('item_id')
                    ->orderByDesc('total_weight')
                    ->limit($limit)
                    ->get();

                $candidates = [];
                foreach ($rows as $row) {
                    $candidates[] = new Candidate(
                        id: (string) $row->item_id,
                        score: (float) $row->total_weight,
                        reasons: ['popular'],
                        generator: $this->name(),
                    );
                }
                return $candidates;
            }

            return [];
        } catch (\Throwable) {
            return [];
        }
    }
}
