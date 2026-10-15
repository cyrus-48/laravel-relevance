<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Filters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class AlreadyPurchasedFilter implements CandidateFilter
{
    public function name(): string
    {
        return 'already_purchased';
    }

    public function apply(array $candidates, RecContext $ctx, string $index = 'default'): array
    {
        if (empty($candidates) || $ctx->userId === null) {
            return $candidates;
        }

        try {
            if (!Schema::hasTable('relevance_interactions')) {
                return $candidates;
            }

            $candidateIds = array_map(static fn (Candidate $c) => $c->id, $candidates);

            $purchasedIds = DB::table('relevance_interactions')
                ->where('index', $index)
                ->where('user_ref', $ctx->userId)
                ->whereIn('type', ['purchase', 'convert'])
                ->whereIn('item_id', $candidateIds)
                ->pluck('item_id')
                ->map(static fn ($id) => (string) $id)
                ->all();

            if (empty($purchasedIds)) {
                return $candidates;
            }

            $purchasedMap = array_flip($purchasedIds);

            return array_values(array_filter(
                $candidates,
                static fn (Candidate $c) => !isset($purchasedMap[$c->id])
            ));
        } catch (\Throwable) {
            return $candidates;
        }
    }
}
