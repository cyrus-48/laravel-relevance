<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Relevance\Contracts\TextRetriever;
use Relevance\Contracts\VectorRetriever;
use Relevance\Data\RetrievalRequest;
use Relevance\Exceptions\MissingTenantScopeException;
use Relevance\Facades\Relevance;
use Relevance\Filters\Filter;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;
use Relevance\Schema\IndexRegistry;
use RuntimeException;

/**
 * "Similar items" by content: nearest neighbours of the seed item's own embedding.
 *
 * The seed's stored vector is looked up by id and used as the query vector, so no
 * embedding provider call is needed at request time. Items without a vector yield no
 * candidates (there is no content signal) rather than text-matching on the id.
 *
 * Failures are not swallowed here: PlacementRunner records them as
 * `generator_failed:content_similar` in the result's degraded list.
 */
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

        $driver = Relevance::driver();
        if (!$driver instanceof TextRetriever || !$driver instanceof VectorRetriever) {
            throw new RuntimeException("Driver [{$driver->name()}] cannot serve content similarity (needs text and vector retrieval).");
        }

        $scope = $this->tenantFilter($ctx);

        // 1. Load the seed document to read its stored vector.
        $seedFilter = $scope === null
            ? Filter::eq('id', $ctx->seedItem)
            : Filter::all(Filter::eq('id', $ctx->seedItem), $scope);

        $seed = $driver->text(new RetrievalRequest(index: $index, query: '', filter: $seedFilter, limit: 1));
        $vectorField = app(IndexRegistry::class)->get($index)?->vectorFieldName() ?? 'embedding';
        $rawVector = $seed->hits[0]->fields[$vectorField] ?? null;

        if (!is_array($rawVector) || $rawVector === []) {
            return [];
        }

        /** @var list<float> $vector */
        $vector = array_values(array_map('floatval', $rawVector));

        // 2. Nearest neighbours, excluding the seed itself, inside the same tenant scope.
        $notSeed = Filter::not(Filter::eq('id', $ctx->seedItem));
        $filter = $scope === null ? $notSeed : Filter::all($notSeed, $scope);

        $result = $driver->vector(
            new RetrievalRequest(index: $index, query: '', filter: $filter, limit: $limit),
            $vector,
        );

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
    }

    private function tenantFilter(RecContext $ctx): ?Filter
    {
        if ($ctx->tenantId !== null) {
            return Filter::eq((string) config('relevance.tenancy.field', 'tenant_id'), $ctx->tenantId);
        }

        if ((bool) config('relevance.tenancy.required', false)) {
            throw new MissingTenantScopeException('Tenancy is required but no tenant was set on the recommendation context.');
        }

        return null;
    }
}
