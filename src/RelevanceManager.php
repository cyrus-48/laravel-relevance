<?php

declare(strict_types=1);

namespace Relevance;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Relevance\Analytics\SearchLogger;
use Relevance\Contracts\Driver;
use Relevance\Contracts\HybridRetriever;
use Relevance\Contracts\TextRetriever;
use Relevance\Contracts\VectorRetriever;
use Relevance\Data\EmbedKind;
use Relevance\Data\Hit;
use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;
use Relevance\Data\SearchMode;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Drivers\DriverManager;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Filters\Filter;
use Relevance\Search\Boosts\BoostEngine;
use Relevance\Search\Fusion\ReciprocalRankFusion;
use Relevance\Search\Profiles\ProfileDefinition;
use Relevance\Search\Profiles\ProfileRepository;
use Relevance\Search\Rules\RuleEngine;
use Relevance\Search\Rules\RuleRepository;
use Relevance\Recommendations\Data\PlacementDefinition;
use Relevance\Recommendations\Filters\FilterRegistry;
use Relevance\Recommendations\Generators\GeneratorRegistry;
use Relevance\Recommendations\PlacementRegistry;
use Relevance\Recommendations\PlacementRunner;
use Relevance\Recommendations\RecommendationBuilder;
use Relevance\Search\Diversify\FieldCollapser;
use Relevance\Search\Federated\MultiSearchBuilder;
use Relevance\Search\Rag\RagRequestBuilder;
use Relevance\Search\Reranking\RerankManager;
use Relevance\Search\SearchBuilder;
use Relevance\Search\SearchExplainResult;
use Relevance\Search\Understanding\QueryNormalizer;
use Relevance\Search\Understanding\SynonymExpander;
use Relevance\Search\Understanding\SynonymRepository;
use Relevance\Support\Deadline;
use Relevance\Testing\RelevanceFake;
use Relevance\Tracking\Tracker;
use Throwable;

class RelevanceManager
{
    private ?RelevanceFake $fake = null;

    public function __construct(
        protected readonly Application $app,
        protected readonly DriverManager $driverManager,
        protected readonly ?EmbeddingManager $embeddingManager = null,
        protected readonly ?ProfileRepository $profileRepository = null,
        protected readonly ?RuleEngine $ruleEngine = null,
        protected readonly ?RuleRepository $ruleRepository = null,
        protected readonly ?SynonymRepository $synonymRepository = null,
        protected readonly ?SynonymExpander $synonymExpander = null,
        protected readonly ?QueryNormalizer $queryNormalizer = null,
        protected readonly ?BoostEngine $boostEngine = null,
        protected readonly ?SearchLogger $searchLogger = null,
        protected readonly ?Tracker $tracker = null,
        protected readonly ?PlacementRunner $placementRunner = null,
        protected readonly ?PlacementRegistry $placementRegistry = null,
        protected readonly ?\Relevance\Experiments\ExperimentManager $experimentManager = null,
        protected readonly ?\Relevance\Evaluation\EvaluationRunner $evaluationRunner = null,
        protected readonly ?RerankManager $rerankManager = null,
    ) {}

    public function placements(): PlacementRegistry
    {
        return $this->placementRegistry ?? new PlacementRegistry();
    }

    public function placement(string|PlacementDefinition $name): RecommendationBuilder
    {
        $runner = $this->placementRunner ?? new PlacementRunner(
            $this->placements(),
            new GeneratorRegistry(),
            new FilterRegistry(),
        );

        return new RecommendationBuilder($runner, $name);
    }

    public function driver(?string $name = null): Driver
    {
        if ($this->fake !== null) {
            return $this->fake->driver();
        }

        return $this->driverManager->driver($name);
    }

    public function embeddings(): ?EmbeddingManager
    {
        if ($this->fake !== null) {
            return null;
        }

        return $this->embeddingManager;
    }

    public function profiles(): ProfileRepository
    {
        return $this->profileRepository ?? new ProfileRepository();
    }

    public function rules(): RuleRepository
    {
        return $this->ruleRepository ?? new RuleRepository();
    }

    public function synonyms(): SynonymRepository
    {
        return $this->synonymRepository ?? new SynonymRepository();
    }

    public function track(mixed $user = null): Tracker
    {
        $tracker = $this->tracker ?? new Tracker();
        if ($user !== null) {
            $tracker->forUser($user);
        }
        return $tracker;
    }

    public function alias(string $anonId, mixed $user): void
    {
        $this->track()->alias($anonId, $user);
    }

    public function fake(): RelevanceFake
    {
        return $this->fake ??= new RelevanceFake();
    }

    public function experiments(): \Relevance\Experiments\ExperimentManager
    {
        return $this->experimentManager ?? new \Relevance\Experiments\ExperimentManager();
    }

    public function evaluation(): \Relevance\Evaluation\EvaluationRunner
    {
        return $this->evaluationRunner ?? new \Relevance\Evaluation\EvaluationRunner();
    }

    public function rerankers(): RerankManager
    {
        return $this->rerankManager ?? new RerankManager();
    }

    /**
     * @param array<string, float> $indexWeights
     */
    public function multi(array $indexWeights): MultiSearchBuilder
    {
        return new MultiSearchBuilder($indexWeights);
    }

    public function retrieve(string $query): RagRequestBuilder
    {
        return new RagRequestBuilder($query);
    }

    public function privacy(): \Relevance\Support\Privacy\PrivacyManager
    {
        return $this->app->make(\Relevance\Support\Privacy\PrivacyManager::class);
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    public function index(string $name): SearchBuilder
    {
        return new SearchBuilder($name);
    }

    public function search(SearchRequest|string $queryOrRequest, string $index = 'default'): SearchResponse
    {
        if ($this->fake !== null) {
            return $this->fake->search($queryOrRequest, $index);
        }

        $request = is_string($queryOrRequest)
            ? new SearchRequest(index: $index, query: $queryOrRequest)
            : $queryOrRequest;

        $start = hrtime(true);
        $timings = [];
        $degraded = [];
        $appliedRules = [];

        // 1. Resolve Experiment & Profile
        $assignedVariant = null;
        $expAssignment = $this->experiments()->assign($request);
        if ($expAssignment !== null && $request->profile === null) {
            $profileName = $expAssignment['profile'];
            $assignedVariant = $expAssignment['variant'];
        } else {
            $profileName = $request->profile ?? 'default';
        }

        $profileRepo = $this->profiles();
        $profile = $profileRepo->resolve($profileName, $request->index);

        // 2. Query Understanding: Normalization
        $normalizer = $this->queryNormalizer ?? new QueryNormalizer();
        $normalizedQuery = $normalizer->normalize($request->query);

        // 3. Pre-Retrieval Rules (Redirect, Rewrite, Inject Filters, Switch Profile)
        $ruleEngine = $this->ruleEngine ?? new RuleEngine($this->rules(), $profileRepo);
        $preEval = $ruleEngine->evaluatePre($request, $profile);
        $currentRequest = $preEval->request;
        $profile = $preEval->profile;
        $redirectUrl = $preEval->redirectUrl;
        $appliedRules = array_merge($appliedRules, $preEval->rulesApplied);

        // 4. Query Understanding: Synonym Expansion
        $synonymExpander = $this->synonymExpander ?? new SynonymExpander($this->synonyms());
        $expandedQuery = $synonymExpander->expand($currentRequest->query, $currentRequest->index);

        // 5. Scope Injection (Tenant isolation)
        $tenancyRequired = (bool) config('relevance.tenancy.required', false);
        $withoutScopes = (bool) ($currentRequest->options['without_scopes'] ?? false);

        if ($tenancyRequired && !$withoutScopes && $currentRequest->context->tenant === null) {
            throw new \Relevance\Exceptions\MissingTenantScopeException('Tenancy is required but no tenant scope was provided on SearchContext.');
        }

        $finalFilter = $currentRequest->filter;
        if ($currentRequest->context->tenant !== null) {
            $tenantField = (string) config('relevance.tenancy.field', 'tenant_id');
            $tenantFilter = Filter::eq($tenantField, $currentRequest->context->tenant);
            $finalFilter = $finalFilter === null ? $tenantFilter : Filter::all($finalFilter, $tenantFilter);
        }

        // 6. Retrieval
        $driver = $this->driver();
        $retrievalRequest = new RetrievalRequest(
            index: $currentRequest->index,
            query: $expandedQuery,
            filter: $finalFilter,
            limit: max($currentRequest->limit, $profile->candidatePool),
            sort: $currentRequest->sort,
            facets: $currentRequest->facets,
            context: $currentRequest->context,
            options: $currentRequest->options,
        );

        $semanticRatio = $currentRequest->semanticRatio ?? $profile->semanticRatio;
        $effectiveMode = $currentRequest->mode;

        // Query Embedding if Vector or Hybrid
        $queryEmbedding = [];
        if ($effectiveMode !== SearchMode::Text && $this->embeddingManager !== null) {
            $embedStart = hrtime(true);
            try {
                $queryEmbedding = $this->embeddingManager->embed($currentRequest->query, kind: EmbedKind::Query);
            } catch (Throwable) {
                $degraded[] = 'embedding';
            }
            $timings['embedding'] = (hrtime(true) - $embedStart) / 1e6;
        }

        $retrieval = null;
        if ($effectiveMode === SearchMode::Text || empty($queryEmbedding)) {
            if (!$driver instanceof TextRetriever) {
                throw new \RuntimeException("Driver [{$driver->name()}] does not support text retrieval.");
            }
            $retrievalStart = hrtime(true);
            $retrieval = $driver->text($retrievalRequest);
            $timings['text_retrieval'] = (hrtime(true) - $retrievalStart) / 1e6;
        } elseif ($effectiveMode === SearchMode::Vector) {
            if (!$driver instanceof VectorRetriever) {
                throw new \RuntimeException("Driver [{$driver->name()}] does not support vector retrieval.");
            }
            $retrievalStart = hrtime(true);
            $retrieval = $driver->vector($retrievalRequest, $queryEmbedding);
            $timings['vector_retrieval'] = (hrtime(true) - $retrievalStart) / 1e6;
        } else {
            $retrievalStart = hrtime(true);
            if ($driver instanceof HybridRetriever && $driver->capabilities()->has(\Relevance\Contracts\Capability::NativeHybrid)) {
                $retrieval = $driver->hybrid($retrievalRequest, $queryEmbedding, $semanticRatio);
                $timings['native_hybrid_retrieval'] = (hrtime(true) - $retrievalStart) / 1e6;
            } else {
                if (!$driver instanceof TextRetriever || !$driver instanceof VectorRetriever) {
                    throw new \RuntimeException("Driver [{$driver->name()}] does not support hybrid retrieval.");
                }

                $textResult = $driver->text($retrievalRequest);
                $vectorResult = $driver->vector($retrievalRequest, $queryEmbedding);

                $k = (int) ($profile->fusion['k'] ?? 60);
                $fuser = new ReciprocalRankFusion(k: $k);
                $fusedHits = $fuser->fuse(
                    lists: [
                        'text'   => $textResult->hits,
                        'vector' => $vectorResult->hits,
                    ],
                    weights: [
                        'text'   => 1.0 - $semanticRatio,
                        'vector' => $semanticRatio,
                    ],
                );

                $retrieval = new RetrievalResult(
                    hits: $fusedHits,
                    total: max($textResult->total, $vectorResult->total),
                    facets: $textResult->facets,
                );
                $timings['hybrid_retrieval'] = (hrtime(true) - $retrievalStart) / 1e6;
            }
        }

        $hits = $retrieval->hits;

        // 7. Business Signal Boosts
        if (!empty($profile->boosts)) {
            $boostStart = hrtime(true);
            $boostEngine = $this->boostEngine ?? new BoostEngine();
            $hits = $boostEngine->apply($hits, $profile->boosts, $profile->boostCap);
            $timings['boosts'] = (hrtime(true) - $boostStart) / 1e6;
        }

        // 8. Post-Retrieval Rules (Pin, Bury, Exclude, Rule Boost)
        $postEval = $ruleEngine->evaluatePost($hits, $request->query, $request->index);
        $hits = $postEval->hits;
        $appliedRules = array_merge($appliedRules, $postEval->rulesApplied);

        // 9. Re-ranking (Top-R) with Graceful Timeout/Degradation
        if (!empty($profile->rerank)) {
            $rerankStart = hrtime(true);
            $providerName = (string) ($profile->rerank['provider'] ?? 'cohere');
            $reranker = $this->rerankers()->get($providerName);

            if ($reranker !== null) {
                $topR = (int) ($profile->rerank['top'] ?? 50);
                $timeoutMs = (int) ($profile->rerank['timeout_ms'] ?? 200);
                $deadline = Deadline::in($timeoutMs);

                try {
                    $hits = $reranker->rerank($currentRequest->query, $hits, new \Relevance\Data\RerankOptions(
                        deadline: $deadline,
                        topR: $topR,
                        model: isset($profile->rerank['model']) ? (string) $profile->rerank['model'] : null,
                    ));
                    $timings['rerank'] = (hrtime(true) - $rerankStart) / 1e6;
                } catch (Throwable) {
                    // Graceful degradation on rerank timeout or error
                    $degraded[] = 'rerank';
                }
            }
        }

        // 10. Diversification & Field Collapse
        if (!empty($profile->diversify)) {
            if (isset($profile->diversify['max_per']) && is_array($profile->diversify['max_per'])) {
                /** @var array<string, int> $maxPer */
                $maxPer = array_map('intval', $profile->diversify['max_per']);
                $collapser = new FieldCollapser();
                $hits = $collapser->collapse($hits, $maxPer);
            }
        }

        // 11. Pagination / Window Slicing
        $offset = (int) ($currentRequest->options['offset'] ?? 0);
        $finalHits = array_slice($hits, $offset, $currentRequest->limit);

        $timings['total'] = (hrtime(true) - $start) / 1e6;

        $searchId = 'srch_' . bin2hex(random_bytes(8));

        $response = new SearchResponse(
            hits: $finalHits,
            total: max($retrieval->total, count($hits)),
            estimatedTotal: $retrieval->estimatedTotal,
            facets: $retrieval->facets,
            searchId: $searchId,
            timings: $timings,
            degraded: $degraded,
            appliedRules: array_values(array_unique($appliedRules)),
            profile: $profile->name,
            variant: $assignedVariant,
            redirectUrl: $redirectUrl,
        );

        // 10. Record Experiment Exposure if assigned
        if ($expAssignment !== null && $assignedVariant !== null) {
            $unitId = $currentRequest->context->userId ?? $currentRequest->context->anonId;
            if ($unitId !== null) {
                $this->experiments()->recordExposure(
                    experiment: $expAssignment['experiment'],
                    unitId: $unitId,
                    variant: $assignedVariant,
                    searchId: $searchId,
                );
            }
        }

        // 11. Telemetry & Analytics Recording
        $searchLogger = $this->searchLogger ?? new SearchLogger();
        $searchLogger->record(
            response: $response,
            request: $currentRequest,
            userRef: $currentRequest->context->userId,
            variant: $assignedVariant,
        );

        return $response;
    }

    public function explain(SearchRequest|string $queryOrRequest, string $index = 'default'): SearchExplainResult
    {
        $request = is_string($queryOrRequest)
            ? new SearchRequest(index: $index, query: $queryOrRequest)
            : $queryOrRequest;

        $profileRepo = $this->profiles();
        $profile = $profileRepo->resolve($request->profile ?? 'default', $request->index);

        $normalizer = $this->queryNormalizer ?? new QueryNormalizer();
        $normalizedQuery = $normalizer->normalize($request->query);

        $ruleEngine = $this->ruleEngine ?? new RuleEngine($this->rules(), $profileRepo);
        $preEval = $ruleEngine->evaluatePre($request, $profile);
        $currentRequest = $preEval->request;
        $profile = $preEval->profile;
        $redirectUrl = $preEval->redirectUrl;
        $rulesApplied = $preEval->rulesApplied;

        $synonymExpander = $this->synonymExpander ?? new SynonymExpander($this->synonyms());
        $synonymExpansions = $synonymExpander->getExpansions($currentRequest->query, $currentRequest->index);

        $response = $this->search($currentRequest, $currentRequest->index);

        return new SearchExplainResult(
            originalQuery: $request->query,
            normalizedQuery: $normalizedQuery,
            synonymExpansions: $synonymExpansions,
            finalFilter: $currentRequest->filter,
            profileName: $profile->name,
            rulesApplied: array_values(array_unique(array_merge($rulesApplied, $response->appliedRules))),
            redirectUrl: $redirectUrl,
            retrievedHits: $response->hits,
            finalHits: $response->hits,
            timings: $response->timings,
            degraded: $response->degraded,
        );
    }

    // ==========================================
    // Proxies for testing assertions when faked
    // ==========================================

    public function assertIndexed(string $index, ?Closure $callback = null): void
    {
        $this->fake()->assertIndexed($index, $callback);
    }

    public function assertSearched(string $index, string|Closure|null $queryOrCallback = null): void
    {
        $this->fake()->assertSearched($index, $queryOrCallback);
    }

    public function assertTracked(string $type, mixed $item = null): void
    {
        $this->fake()->assertTracked($type, $item);
    }
}
