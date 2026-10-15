<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Relevance\Data\RetrievalRequest;
use Relevance\Facades\Relevance;
use Relevance\Filters\Filter;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

class ContentSimilarGenerator implements CandidateGenerator
{
    public function name(): string
    {
        return 'content_similar';
    }

    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array
    {
        if ($ctx->seedItem === null) {
            return [];
        }

        try {
            $driver = Relevance::driver();
            if (!$driver instanceof \Relevance\Contracts\TextRetriever) {
                return [];
            }

            // Simple text search for seedItem id/title in index excluding the seed item itself
            $retrievalRequest = new RetrievalRequest(
                index: $index,
                query: $ctx->seedItem,
                filter: Filter::not(Filter::eq('id', $ctx->seedItem)),
                limit: $limit,
            );

            $result = $driver->text($retrievalRequest);

            $candidates = [];
            foreach ($result->hits as $hit) {
                if ((string) $hit->id === (string) $ctx->seedItem) {
                    continue;
                }

                $candidates[] = new Candidate(
                    id: (string) $hit->id,
                    score: (float) $hit->score,
                    reasons: ["similar_content:{$ctx->seedItem}"],
                    generator: $this->name(),
                );
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        }
    }
}
