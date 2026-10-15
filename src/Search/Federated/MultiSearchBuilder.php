<?php

declare(strict_types=1);

namespace Relevance\Search\Federated;

use Relevance\Data\Hit;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Facades\Relevance;
use Relevance\Search\Fusion\ReciprocalRankFusion;

class MultiSearchBuilder
{
    private string $query = '';
    private int $limit = 20;

    /**
     * @param array<string, float> $indexWeights
     */
    public function __construct(
        private readonly array $indexWeights,
    ) {}

    public function search(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function query(string $query): self
    {
        return $this->search($query);
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): FederatedSearchResponse
    {
        $start = hrtime(true);
        $timings = [];
        $byIndex = [];
        $lists = [];
        $weights = [];

        foreach ($this->indexWeights as $index => $weight) {
            $t0 = hrtime(true);
            $req = new SearchRequest(
                index: $index,
                query: $this->query,
                limit: $this->limit * 2,
            );

            $response = Relevance::search($req, $index);
            $byIndex[$index] = $response;
            $timings[$index] = (hrtime(true) - $t0) / 1e6;

            // Tag hits with index identifier
            $taggedHits = [];
            foreach ($response->hits as $hit) {
                $fields = $hit->fields;
                $fields['_index'] = $index;
                $taggedHits[] = new Hit(
                    id: "{$index}:{$hit->id}",
                    score: $hit->score,
                    fields: $fields,
                    highlights: $hit->highlights,
                    breakdown: $hit->breakdown,
                );
            }

            $lists[$index] = $taggedHits;
            $weights[$index] = $weight;
        }

        $fuser = new ReciprocalRankFusion(k: 60);
        $fusedHits = $fuser->fuse($lists, $weights);

        $finalHits = array_slice($fusedHits, 0, $this->limit);
        $total = array_sum(array_map(static fn (SearchResponse $r) => $r->total, $byIndex));

        $timings['total'] = (hrtime(true) - $start) / 1e6;

        return new FederatedSearchResponse(
            hits: $finalHits,
            byIndex: $byIndex,
            total: $total,
            timings: $timings,
        );
    }
}
