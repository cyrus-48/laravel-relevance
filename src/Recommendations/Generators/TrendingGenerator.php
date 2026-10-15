<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class TrendingGenerator implements CandidateGenerator
{
    public function name(): string
    {
        return 'trending';
    }

    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array
    {
        try {
            if (!Schema::hasTable('relevance_interactions')) {
                return [];
            }

            $window = (string) ($ctx->context['window'] ?? '7d');
            $days = 7;
            if (preg_match('/^(\d+)d$/', $window, $matches)) {
                $days = (int) $matches[1];
            } elseif (preg_match('/^(\d+)h$/', $window, $matches)) {
                $days = max(1, (int) round(((int) $matches[1]) / 24));
            }

            $since = Carbon::now()->subDays($days);

            $query = DB::table('relevance_interactions')
                ->select('item_id', DB::raw('SUM(COALESCE(weight, 1.0)) as score'))
                ->where('index', $index)
                ->where('occurred_at', '>=', $since)
                ->groupBy('item_id')
                ->orderByDesc('score')
                ->limit($limit);

            if ($ctx->segment !== null) {
                // E.g. segment = 'category:shoes' or context
                $query->where('placement', $ctx->segment);
            }

            $rows = $query->get();

            $candidates = [];
            foreach ($rows as $row) {
                $candidates[] = new Candidate(
                    id: (string) $row->item_id,
                    score: (float) $row->score,
                    reasons: ['trending'],
                    generator: $this->name(),
                );
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        }
    }
}
