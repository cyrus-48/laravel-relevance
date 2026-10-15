<?php

declare(strict_types=1);

namespace Relevance\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;

class SearchLogger
{
    public function record(
        SearchResponse $response,
        SearchRequest $request,
        ?string $userRef = null,
        ?string $variant = null,
    ): void {
        try {
            if (!Schema::hasTable('relevance_search_logs')) {
                return;
            }

            $queryNorm = mb_strtolower(trim($request->query));
            $queryHash = hash('sha256', $queryNorm);
            $filtersHash = $request->filter !== null
                ? hash('sha256', serialize($request->filter))
                : null;

            $totalLatency = (float) ($response->timings['total'] ?? 0.0);

            DB::table('relevance_search_logs')->insert([
                'search_id' => $response->searchId,
                'index' => $request->index,
                'query_norm' => $queryNorm,
                'query_hash' => $queryHash,
                'filters_hash' => $filtersHash,
                'profile' => $response->profile ?? $request->profile,
                'variant' => $variant,
                'total' => $response->total,
                'latency_ms' => $totalLatency,
                'stages' => json_encode($response->timings),
                'degraded' => !empty($response->degraded) ? json_encode($response->degraded) : null,
                'user_ref' => $userRef,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Fail-soft: logging never breaks customer search
        }
    }
}
