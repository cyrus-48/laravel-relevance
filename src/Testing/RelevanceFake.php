<?php

declare(strict_types=1);

namespace Relevance\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Relevance\Data\Document;
use Relevance\Data\Hit;
use Relevance\Data\IndexSchema;
use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Drivers\Memory\MemoryDriver;
use Relevance\Embeddings\FakeEmbeddingProvider;

class RelevanceFake
{
    private MemoryDriver $memoryDriver;
    private FakeEmbeddingProvider $fakeEmbeddingProvider;

    /** @var array<string, list<Document>> */
    private array $indexedDocs = [];

    /** @var list<array{index: string, query: string, request: SearchRequest|RetrievalRequest}> */
    private array $searches = [];

    /** @var list<array{type: string, item: mixed, context: mixed}> */
    private array $tracked = [];

    public function __construct()
    {
        $this->memoryDriver = new MemoryDriver();
        $this->fakeEmbeddingProvider = new FakeEmbeddingProvider();
    }

    public function driver(): MemoryDriver
    {
        return $this->memoryDriver;
    }

    public function embeddings(): FakeEmbeddingProvider
    {
        return $this->fakeEmbeddingProvider;
    }

    /**
     * @param list<Document|array<string, mixed>> $documents
     */
    public function seed(string $index, array $documents): self
    {
        $physical = $index . '_test';
        if ($this->memoryDriver->resolveAlias($index) === null) {
            $this->memoryDriver->create(new IndexSchema($index), $physical);
            $this->memoryDriver->swapAlias($index, $physical);
        }

        $docs = [];
        foreach ($documents as $doc) {
            if ($doc instanceof Document) {
                $docs[] = $doc;
            } else {
                $id = (string) ($doc['id'] ?? uniqid());
                $fields = $doc;
                unset($fields['id']);
                $docs[] = new Document(id: $id, version: 1, fields: $fields);
            }
        }

        $this->memoryDriver->upsert($index, $docs);
        $this->indexedDocs[$index] = array_merge($this->indexedDocs[$index] ?? [], $docs);

        return $this;
    }

    public function search(SearchRequest|string $queryOrRequest, string $index = 'default'): SearchResponse
    {
        $request = is_string($queryOrRequest)
            ? new SearchRequest(index: $index, query: $queryOrRequest)
            : $queryOrRequest;

        $this->searches[] = [
            'index'   => $request->index,
            'query'   => $request->query,
            'request' => $request,
        ];

        $retrievalRequest = new RetrievalRequest(
            index: $request->index,
            query: $request->query,
            filter: $request->filter,
            limit: $request->limit,
            sort: $request->sort,
            facets: $request->facets,
            context: $request->context,
            options: $request->options,
        );

        $retrieval = $this->memoryDriver->text($retrievalRequest);

        return new SearchResponse(
            hits: $retrieval->hits,
            total: $retrieval->total,
            estimatedTotal: $retrieval->estimatedTotal,
            facets: $retrieval->facets,
            searchId: 'fake-' . uniqid(),
            profile: $request->profile,
        );
    }

    public function track(string $type, mixed $item = null, mixed $context = null): void
    {
        $this->tracked[] = [
            'type'    => $type,
            'item'    => $item,
            'context' => $context,
        ];
    }

    public function recordTracked(string $type, mixed $item = null, mixed $context = null): void
    {
        $this->track($type, $item, $context);
    }

    public function recordIndexed(string $index, Document $document): void
    {
        $this->indexedDocs[$index][] = $document;
    }

    // ==========================================
    // Assertions
    // ==========================================

    public function assertIndexed(string $index, ?Closure $callback = null): void
    {
        PHPUnit::assertArrayHasKey(
            $index,
            $this->indexedDocs,
            "No documents were indexed for index [{$index}]."
        );

        if ($callback === null) {
            PHPUnit::assertNotEmpty($this->indexedDocs[$index]);
            return;
        }

        $matched = false;
        foreach ($this->indexedDocs[$index] as $doc) {
            if ($callback($doc->fields, $doc)) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, "Failed asserting that document was indexed in [{$index}] matching condition.");
    }

    public function assertSearched(string $index, string|Closure|null $queryOrCallback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->searches,
            "No searches were performed."
        );

        $matched = false;
        foreach ($this->searches as $search) {
            if ($search['index'] !== $index) {
                continue;
            }

            if ($queryOrCallback === null) {
                $matched = true;
                break;
            }

            if (is_string($queryOrCallback)) {
                if ($search['query'] === $queryOrCallback) {
                    $matched = true;
                    break;
                }
            } elseif ($queryOrCallback($search['query'], $search['request'])) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, "Failed asserting that [{$index}] was searched with expected query/condition.");
    }

    public function assertTracked(string $type, mixed $item = null): void
    {
        PHPUnit::assertNotEmpty($this->tracked, "No interactions were tracked.");

        $matched = false;
        foreach ($this->tracked as $entry) {
            if ($entry['type'] !== $type) {
                continue;
            }

            if ($item === null) {
                $matched = true;
                break;
            }

            if ($entry['item'] === $item) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, "Failed asserting that interaction [{$type}] was tracked.");
    }
}
