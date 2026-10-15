<?php

declare(strict_types=1);

namespace Relevance\Search\Reranking;

use Illuminate\Support\Facades\Http;
use Relevance\Contracts\Reranker;
use Relevance\Data\Hit;
use Relevance\Data\RerankOptions;
use RuntimeException;

class HttpReranker implements Reranker
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $apiKey = null,
        private readonly string $model = 'rerank-v3.5',
        private readonly int $timeoutMs = 500,
    ) {}

    public function rerank(string $query, array $hits, RerankOptions $options): array
    {
        if (empty($hits)) {
            return [];
        }

        $topR = min($options->topR, count($hits));
        $toRerank = array_slice($hits, 0, $topR);
        $remainder = array_slice($hits, $topR);

        $documents = [];
        foreach ($toRerank as $hit) {
            $title = $hit->fields['title'] ?? $hit->fields['name'] ?? null;
            $body = $hit->fields['body'] ?? $hit->fields['description'] ?? null;
            $text = trim(($title ? (string) $title : '') . "\n" . ($body ? (string) $body : ''));
            $documents[] = $text !== '' ? $text : "id: {$hit->id}";
        }

        $request = Http::timeout(max(0.05, $this->timeoutMs / 1000));
        if ($this->apiKey !== null) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post($this->url, [
            'model' => $options->model ?? $this->model,
            'query' => $query,
            'documents' => $documents,
            'top_n' => $topR,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("HttpReranker failed with status [{$response->status()}]: {$response->body()}");
        }

        /** @var array{results?: list<array{index: int, relevance_score: float}>} $data */
        $data = $response->json();
        $results = $data['results'] ?? [];

        if (empty($results)) {
            return $hits;
        }

        $reranked = [];
        foreach ($results as $item) {
            $origIndex = (int) $item['index'];
            if (isset($toRerank[$origIndex])) {
                $score = (float) $item['relevance_score'];
                $reranked[] = $toRerank[$origIndex]->with(
                    score: $score,
                    stage: 'rerank',
                    detail: ['model' => $options->model ?? $this->model, 'score' => $score]
                );
            }
        }

        return array_merge($reranked, $remainder);
    }
}
