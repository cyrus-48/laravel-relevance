<?php

declare(strict_types=1);

namespace Relevance\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Relevance\Contracts\Driver;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\RelevanceManager;
use Relevance\Testing\RelevanceFake;

/**
 * @method static Driver driver(?string $name = null)
 * @method static RelevanceFake fake()
 * @method static bool isFaked()
 * @method static \Relevance\Search\SearchBuilder index(string $name)
 * @method static SearchResponse search(SearchRequest|string $queryOrRequest, string $index = 'default')
 * @method static \Relevance\Search\Profiles\ProfileRepository profiles()
 * @method static \Relevance\Search\Rules\RuleRepository rules()
 * @method static \Relevance\Search\Understanding\SynonymRepository synonyms()
 * @method static \Relevance\Tracking\Tracker track(mixed $user = null)
 * @method static \Relevance\Search\SearchExplainResult explain(SearchRequest|string $queryOrRequest, string $index = 'default')
 * @method static \Relevance\Recommendations\RecommendationBuilder placement(string|\Relevance\Recommendations\Data\PlacementDefinition $name)
 * @method static \Relevance\Recommendations\PlacementRegistry placements()
 * @method static \Relevance\Experiments\ExperimentManager experiments()
 * @method static \Relevance\Evaluation\EvaluationRunner evaluation()
 * @method static \Relevance\Search\Reranking\RerankManager rerankers()
 * @method static \Relevance\Search\Federated\MultiSearchBuilder multi(array<string, float> $indexWeights)
 * @method static \Relevance\Search\Rag\RagRequestBuilder retrieve(string $query)
 * @method static \Relevance\Support\Privacy\PrivacyManager privacy()
 * @method static void assertIndexed(string $index, ?Closure $callback = null)
 * @method static void assertSearched(string $index, string|Closure|null $queryOrCallback = null)
 * @method static void assertTracked(string $type, mixed $item = null)
 *
 * @see RelevanceManager
 */
class Relevance extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'relevance';
    }
}
