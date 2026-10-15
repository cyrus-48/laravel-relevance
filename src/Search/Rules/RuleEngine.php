<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

use Relevance\Data\Hit;
use Relevance\Data\SearchRequest;
use Relevance\Filters\Filter;
use Relevance\Search\Profiles\ProfileDefinition;
use Relevance\Search\Profiles\ProfileRepository;

class RuleEngine
{
    public function __construct(
        private readonly RuleRepository $ruleRepository,
        private readonly ?ProfileRepository $profileRepository = null,
    ) {}

    public function evaluatePre(SearchRequest $request, ProfileDefinition $profile): RulePreEvaluation
    {
        $rules = $this->ruleRepository->getRulesForIndex($request->index);
        $rulesApplied = [];
        $currentRequest = $request;
        $currentProfile = $profile;
        $redirectUrl = null;

        foreach ($rules as $rule) {
            if (!$rule->matches($currentRequest->query)) {
                continue;
            }

            $appliedThisRule = false;

            foreach ($rule->actions as $action) {
                $type = (string) ($action['type'] ?? '');

                if ($type === 'redirect' && isset($action['url'])) {
                    $redirectUrl = (string) $action['url'];
                    $appliedThisRule = true;
                } elseif ($type === 'rewrite' && isset($action['query'])) {
                    $currentRequest = new SearchRequest(
                        index: $currentRequest->index,
                        query: (string) $action['query'],
                        filter: $currentRequest->filter,
                        sort: $currentRequest->sort,
                        limit: $currentRequest->limit,
                        cursor: $currentRequest->cursor,
                        facets: $currentRequest->facets,
                        mode: $currentRequest->mode,
                        semanticRatio: $currentRequest->semanticRatio,
                        profile: $currentRequest->profile,
                        context: $currentRequest->context,
                        options: $currentRequest->options,
                    );
                    $appliedThisRule = true;
                } elseif ($type === 'switch_profile' && isset($action['profile'])) {
                    $newProfileName = (string) $action['profile'];
                    if ($this->profileRepository !== null) {
                        $currentProfile = $this->profileRepository->resolve($newProfileName, $currentRequest->index);
                    }
                    $appliedThisRule = true;
                } elseif ($type === 'inject_filter') {
                    $injected = $this->buildFilterFromAction($action);
                    if ($injected !== null) {
                        $newFilter = $currentRequest->filter === null
                            ? $injected
                            : Filter::all($currentRequest->filter, $injected);

                        $currentRequest = new SearchRequest(
                            index: $currentRequest->index,
                            query: $currentRequest->query,
                            filter: $newFilter,
                            sort: $currentRequest->sort,
                            limit: $currentRequest->limit,
                            cursor: $currentRequest->cursor,
                            facets: $currentRequest->facets,
                            mode: $currentRequest->mode,
                            semanticRatio: $currentRequest->semanticRatio,
                            profile: $currentRequest->profile,
                            context: $currentRequest->context,
                            options: $currentRequest->options,
                        );
                        $appliedThisRule = true;
                    }
                }
            }

            if ($appliedThisRule) {
                $rulesApplied[] = $rule->name;
            }
        }

        return new RulePreEvaluation(
            request: $currentRequest,
            profile: $currentProfile,
            redirectUrl: $redirectUrl,
            rulesApplied: $rulesApplied,
        );
    }

    /**
     * @param list<Hit> $hits
     */
    public function evaluatePost(array $hits, string $query, string $index): RulePostEvaluation
    {
        $rules = $this->ruleRepository->getRulesForIndex($index);
        $rulesApplied = [];
        $currentHits = $hits;

        foreach ($rules as $rule) {
            if (!$rule->matches($query)) {
                continue;
            }

            $appliedThisRule = false;

            foreach ($rule->actions as $action) {
                $type = (string) ($action['type'] ?? '');

                if ($type === 'exclude' && isset($action['ids'])) {
                    $excludeIds = array_map('strval', (array) $action['ids']);
                    $currentHits = array_values(array_filter(
                        $currentHits,
                        static fn (Hit $h) => !in_array((string) $h->id, $excludeIds, true)
                    ));
                    $appliedThisRule = true;
                } elseif ($type === 'bury' && isset($action['ids'])) {
                    $buryIds = array_map('strval', (array) $action['ids']);
                    $kept = [];
                    $buried = [];

                    foreach ($currentHits as $hit) {
                        if (in_array((string) $hit->id, $buryIds, true)) {
                            $buried[] = $hit;
                        } else {
                            $kept[] = $hit;
                        }
                    }

                    $currentHits = array_merge($kept, $buried);
                    $appliedThisRule = true;
                } elseif ($type === 'pin' && isset($action['ids'])) {
                    $pinIds = array_values(array_map('strval', (array) $action['ids']));
                    /** @var list<int> $positions */
                    $positions = array_values(array_map('intval', (array) ($action['positions'] ?? [])));

                    $currentHits = $this->applyPinning($currentHits, $pinIds, $positions);
                    $appliedThisRule = true;
                } elseif ($type === 'boost' && isset($action['factor'])) {
                    $factor = (float) $action['factor'];
                    $targetIds = isset($action['ids']) ? array_map('strval', (array) $action['ids']) : null;

                    foreach ($currentHits as $idx => $hit) {
                        if ($targetIds === null || in_array((string) $hit->id, $targetIds, true)) {
                            $currentHits[$idx] = $hit->with(
                                score: $hit->score * $factor,
                                stage: 'rule_boost',
                                detail: ['rule' => $rule->name, 'factor' => $factor]
                            );
                        }
                    }

                    // Re-sort after boost
                    usort($currentHits, static fn (Hit $a, Hit $b) => $b->score <=> $a->score ?: strcmp($a->id, $b->id));
                    $appliedThisRule = true;
                }
            }

            if ($appliedThisRule) {
                $rulesApplied[] = $rule->name;
            }
        }

        return new RulePostEvaluation(
            hits: $currentHits,
            rulesApplied: $rulesApplied,
        );
    }

    /**
     * @param list<Hit> $hits
     * @param list<string> $pinIds
     * @param list<int> $positions
     * @return list<Hit>
     */
    private function applyPinning(array $hits, array $pinIds, array $positions): array
    {
        $pool = [];
        $existingHitsById = [];

        foreach ($hits as $hit) {
            $existingHitsById[(string) $hit->id] = $hit;
            if (!in_array((string) $hit->id, $pinIds, true)) {
                $pool[] = $hit;
            }
        }

        // Prepare pinned hits
        $pinnedList = [];
        foreach ($pinIds as $i => $id) {
            $pos = $positions[$i] ?? ($i + 1);
            $hit = $existingHitsById[$id] ?? new Hit(id: $id, score: 1000.0 - $i);
            $pinnedList[] = ['pos' => $pos, 'hit' => $hit];
        }

        // Sort by desired position ASC
        usort($pinnedList, static fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // Insert into pool at target positions (1-based)
        $result = $pool;
        foreach ($pinnedList as $pinned) {
            $targetIndex = max(0, min(count($result), $pinned['pos'] - 1));
            array_splice($result, $targetIndex, 0, [$pinned['hit']]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function buildFilterFromAction(array $action): ?Filter
    {
        if (isset($action['filter']) && $action['filter'] instanceof Filter) {
            return $action['filter'];
        }

        $field = (string) ($action['field'] ?? '');
        if ($field === '') {
            return null;
        }

        $operator = (string) ($action['operator'] ?? '=');
        $value = $action['value'] ?? null;

        return match ($operator) {
            '<' => Filter::range($field, lt: $value),
            '<=' => Filter::range($field, lte: $value),
            '>' => Filter::range($field, gt: $value),
            '>=' => Filter::range($field, gte: $value),
            'in' => Filter::in($field, array_values((array) $value)),
            default => Filter::eq($field, $value),
        };
    }
}
