<?php

declare(strict_types=1);

namespace Relevance\Search\Understanding;

class SynonymExpander
{
    public function __construct(
        private readonly SynonymRepository $repository,
    ) {}

    public function expand(string $query, string $index, ?string $locale = null): string
    {
        $expansions = $this->getExpansions($query, $index, $locale);
        if (empty($expansions)) {
            return $query;
        }

        $allTokens = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];
        $addedTokens = [];

        foreach ($expansions as $syns) {
            foreach ($syns as $syn) {
                if (!in_array($syn, $allTokens, true) && !in_array($syn, $addedTokens, true)) {
                    $addedTokens[] = $syn;
                }
            }
        }

        if (empty($addedTokens)) {
            return $query;
        }

        return trim($query . ' ' . implode(' ', $addedTokens));
    }

    /**
     * @return array<string, list<string>> token => synonyms found
     */
    public function getExpansions(string $query, string $index, ?string $locale = null): array
    {
        $tokens = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];
        if (empty($tokens)) {
            return [];
        }

        $synonyms = $this->repository->getSynonymsForIndex($index, $locale);
        $oneWay = $synonyms['one_way'];
        $multiWay = $synonyms['multi_way'];

        $result = [];

        foreach ($tokens as $token) {
            $tokenSyns = [];

            // Check one-way
            if (isset($oneWay[$token])) {
                $tokenSyns = array_merge($tokenSyns, $oneWay[$token]);
            }

            // Check multi-way
            foreach ($multiWay as $group) {
                if (in_array($token, $group, true)) {
                    foreach ($group as $word) {
                        if ($word !== $token) {
                            $tokenSyns[] = $word;
                        }
                    }
                }
            }

            if (!empty($tokenSyns)) {
                $result[$token] = array_values(array_unique($tokenSyns));
            }
        }

        return $result;
    }
}
