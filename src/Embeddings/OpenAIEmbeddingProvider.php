<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Illuminate\Support\Facades\Http;
use Relevance\Contracts\EmbeddingProvider;
use Relevance\Data\EmbedKind;
use RuntimeException;

final readonly class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $apiKey,
        private string $model = 'text-embedding-3-small',
        private int $dimensions = 1536,
        private string $baseUrl = 'https://api.openai.com/v1',
        private int $batchSize = 64,
        private bool $allowPii = false,
    ) {}

    public function fingerprint(): string
    {
        return "openai:{$this->model}:{$this->dimensions}:v1";
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function allowPii(): bool
    {
        return $this->allowPii;
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
            $payload = [
                'model'      => $this->model,
                'input'      => $chunk,
                'dimensions' => $this->dimensions,
            ];

            $response = Http::withToken($this->apiKey)
                ->baseUrl($this->baseUrl)
                ->retry(3, 200)
                ->post('/embeddings', $payload);

            if (!$response->successful()) {
                throw new RuntimeException("OpenAI embeddings request failed [{$response->status()}]: {$response->body()}");
            }

            /** @var array{data: list<array{embedding: list<float>, index: int}>} $json */
            $json = $response->json();
            $data = $json['data'];

            // Sort by index in case API returns out of order
            usort($data, static fn ($a, $b) => $a['index'] <=> $b['index']);

            foreach ($data as $item) {
                $allEmbeddings[] = $item['embedding'];
            }
        }

        return $allEmbeddings;
    }
}
