<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class RecentlyViewedGenerator implements CandidateGenerator
{
    public function name(): string
    {
        return 'recently_viewed';
    }

    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array
    {
        if ($ctx->userId === null && $ctx->anonId === null && $ctx->sessionId === null) {
            return [];
        }

        try {
            if (!Schema::hasTable('relevance_interactions')) {
                return [];
            }

            $query = DB::table('relevance_interactions')
                ->where('index', $index)
                ->whereIn('type', ['view', 'click'])
                ->where(function ($q) use ($ctx) {
                    if ($ctx->userId !== null) {
                        $q->orWhere('user_ref', $ctx->userId);
                    }
                    if ($ctx->anonId !== null) {
                        $q->orWhere('anon_ref', $ctx->anonId);
                    }
                    if ($ctx->sessionId !== null) {
                        $q->orWhere('session_id', $ctx->sessionId);
                    }
                })
                ->orderByDesc('occurred_at')
                ->limit($limit * 2); // overfetch to dedupe

            $rows = $query->get();

            $seen = [];
            $candidates = [];

            foreach ($rows as $row) {
                $id = (string) $row->item_id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $candidates[] = new Candidate(
                    id: $id,
                    score: 1.0,
                    reasons: ["because_you_viewed:{$id}"],
                    generator: $this->name(),
                );

                if (count($candidates) >= $limit) {
                    break;
                }
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        }
    }
}
