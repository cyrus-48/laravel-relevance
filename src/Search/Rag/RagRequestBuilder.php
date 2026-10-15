<?php

declare(strict_types=1);

namespace Relevance\Search\Rag;

use Relevance\Data\SearchRequest;
use Relevance\Facades\Relevance;

class RagRequestBuilder
{
    private string $index = 'docs';
    private bool $chunks = true;
    private int $limit = 8;
    private int $maxPerParent = 2;

    public function __construct(
        private readonly string $query,
    ) {}

    public function index(string $index): self
    {
        $this->index = $index;
        return $this;
    }

    public function chunks(bool $enabled = true): self
    {
        $this->chunks = $enabled;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function maxPerParent(int $max): self
    {
        $this->maxPerParent = $max;
        return $this;
    }

    public function get(): RagRetrievalResult
    {
        $start = hrtime(true);

        $request = new SearchRequest(
            index: $this->index,
            query: $this->query,
            limit: $this->limit * 3, // over-fetch to support parent collapse
        );

        $search = Relevance::search($request, $this->index);

        $parentCounts = [];
        $selectedChunks = [];
        $grouped = [];

        foreach ($search->hits as $hit) {
            $parentId = $this->chunks
                ? (string) ($hit->fields['parent_id'] ?? $hit->id)
                : (string) $hit->id;
            $currentParentCount = $parentCounts[$parentId] ?? 0;

            if ($currentParentCount >= $this->maxPerParent) {
                continue;
            }

            $content = (string) ($hit->fields['content'] ?? $hit->fields['body'] ?? $hit->fields['title'] ?? '');
            $citations = [
                'title' => $hit->fields['title'] ?? null,
                'url' => $hit->fields['url'] ?? null,
                'parent_id' => $parentId,
            ];

            $chunk = new ChunkHit(
                id: (string) $hit->id,
                parentId: $parentId,
                content: $content,
                score: (float) $hit->score,
                citations: $citations,
            );

            $parentCounts[$parentId] = $currentParentCount + 1;
            $selectedChunks[] = $chunk;
            $grouped[$parentId][] = $chunk;

            if (count($selectedChunks) >= $this->limit) {
                break;
            }
        }

        $timings = [
            'total' => (hrtime(true) - $start) / 1e6,
        ];

        return new RagRetrievalResult(
            chunks: $selectedChunks,
            groupedByParent: $grouped,
            total: count($selectedChunks),
            timings: $timings,
        );
    }
}
