<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Illuminate\Support\Facades\Http;
use Relevance\Contracts\EmbeddingProvider;
use Relevance\Data\EmbedKind;
use RuntimeException;

final readonly class HttpEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $url,
        private string $model = 'local-model',
        private int $dimensions = 1024,
        private ?string $apiKey = null,
        private int $batchSize = 64,
    ) {}

    public function fingerprint(): string
    {
        return "http:{$this->model}:{$this->dimensions}:v1";
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, EmbedKind $kind = EmbedKind::Document): array
    {
        if (empty($texts)) {
            return [];
        }

        $allEmbeddings = [];
        $batch = max(1, $this->batchSize);
        $chunks = array_chunk($texts, $batch);

        foreach ($chunks as $chunk) {
            $client = Http::retry(2, 100);
            if ($this->apiKey !== null) {
                $client = $client->withToken($this->apiKey);
            }

            $response = $client->post($this->url, [
                'inputs'   => $chunk,
                'is_query' => $kind === EmbedKind::Query,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("Local HTTP embedding service failed [{$response->status()}]: {$response->body()}");
            }

            $json = $response->json();
            $vectors = is_array($json) && isset($json['embeddings']) && is_array($json['embeddings'])
                ? $json['embeddings']
                : (is_array($json) ? $json : []);

            foreach ($vectors as $v) {
                if (is_array($v)) {
                    /** @var list<float> $typedVec */
                    $typedVec = array_values(array_map('floatval', $v));
                    $allEmbeddings[] = $typedVec;
                }
            }
        }

        return $allEmbeddings;
    }
}
