<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Relevance\Contracts\EmbeddingProvider;
use Relevance\Data\EmbedKind;

final readonly class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private int $dimensions = 8,
        private string $model = 'fake-model-v1',
    ) {}

    public function fingerprint(): string
    {
        return "fake:{$this->model}:{$this->dimensions}:v1";
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
        $result = [];

        foreach ($texts as $text) {
            $vector = [];
            $hash = md5($text . ':' . $kind->value);
            for ($i = 0; $i < $this->dimensions; $i++) {
                // Generate a deterministic float between -1.0 and 1.0
                $sub = substr($hash, ($i * 2) % 28, 4);
                $val = (hexdec($sub) % 2000 - 1000) / 1000.0;
                $vector[] = (float) $val;
            }

            // Normalize vector to unit length
            $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));
            if ($norm > 0.0) {
                $vector = array_map(fn ($x) => $x / $norm, $vector);
            }

            $result[] = $vector;
        }

        return $result;
    }
}
