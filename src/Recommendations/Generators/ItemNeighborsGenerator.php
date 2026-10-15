<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class ItemNeighborsGenerator implements CandidateGenerator
{
    public function name(): string
    {
        return 'item_neighbors';
    }

    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array
    {
        if ($ctx->seedItem === null) {
            return [];
        }

        try {
            if (!Schema::hasTable('relevance_item_neighbors')) {
                return [];
            }

            $rows = DB::table('relevance_item_neighbors')
                ->where('index', $index)
                ->where('item_id', $ctx->seedItem)
                ->orderByDesc('score')
                ->limit($limit)
                ->get();

            $candidates = [];
            foreach ($rows as $row) {
                $candidates[] = new Candidate(
                    id: (string) $row->neighbor_id,
                    score: (float) $row->score,
                    reasons: ["similar_to:{$ctx->seedItem}"],
                    generator: $this->name(),
                );
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        }
    }
}
