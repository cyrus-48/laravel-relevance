<?php

declare(strict_types=1);

namespace Relevance\Drivers\Memory;

use Relevance\Contracts\Capability;
use Relevance\Contracts\CapabilitySet;
use Relevance\Contracts\Driver;
use Relevance\Contracts\HybridRetriever;
use Relevance\Contracts\IndexAdmin;
use Relevance\Contracts\Indexer;
use Relevance\Contracts\Suggester;
use Relevance\Contracts\TextRetriever;
use Relevance\Contracts\VectorRetriever;
use Relevance\Data\Document;
use Relevance\Data\Hit;
use Relevance\Data\IndexSchema;
use Relevance\Data\IndexSettings;
use Relevance\Data\IndexStats;
use Relevance\Data\RetrievalRequest;
use Relevance\Data\RetrievalResult;
use Relevance\Data\SuggestRequest;
use Relevance\Data\SuggestResult;
use Relevance\Data\UpsertReport;
use Relevance\Search\Fusion\ReciprocalRankFusion;

final class MemoryDriver implements
    Driver,
    IndexAdmin,
    Indexer,
    TextRetriever,
    VectorRetriever,
    HybridRetriever,
    Suggester
{
    /** @var array<string, array<string, Document>> */
    private array $indices = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, IndexSchema> */
    private array $schemas = [];

    /** @var array<string, IndexSettings> */
    private array $settings = [];

    private readonly MemoryFilterCompiler $filterCompiler;

    public function __construct()
    {
        $this->filterCompiler = new MemoryFilterCompiler();
    }

    public function name(): string
    {
        return 'memory';
    }

    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of(
            Capability::Facets,
            Capability::Geo,
            Capability::Highlight,
            Capability::NativeHybrid,
            Capability::Vectors,
            Capability::ExternalVersioning,
            Capability::AliasSwap,
            Capability::Suggest,
            Capability::Synonyms,
            Capability::FilterPushdown,
        );
    }

    // ==========================================
    // IndexAdmin implementation
    // ==========================================

    public function create(IndexSchema $schema, string $physicalName): void
    {
        $this->indices[$physicalName] = [];
        $this->schemas[$physicalName] = $schema;
        if ($schema->settings !== null) {
            $this->settings[$physicalName] = $schema->settings;
        }
    }

    public function drop(string $physicalName): void
    {
        unset($this->indices[$physicalName], $this->schemas[$physicalName], $this->settings[$physicalName]);
        foreach ($this->aliases as $alias => $target) {
            if ($target === $physicalName) {
                unset($this->aliases[$alias]);
            }
        }
    }

    public function swapAlias(string $alias, string $physicalName): void
    {
        $this->aliases[$alias] = $physicalName;
    }

    public function resolveAlias(string $alias): ?string
    {
        return $this->aliases[$alias] ?? (isset($this->indices[$alias]) ? $alias : null);
    }

    public function stats(string $physicalName): IndexStats
    {
        $physical = $this->resolveAlias($physicalName) ?? $physicalName;
        $count = isset($this->indices[$physical]) ? count($this->indices[$physical]) : 0;

        return new IndexStats(documentCount: $count, sizeBytes: $count * 512);
    }

    public function applySettings(string $physicalName, IndexSettings $settings): void
    {
        $physical = $this->resolveAlias($physicalName) ?? $physicalName;
        $this->settings[$physical] = $settings;
    }

    // ==========================================
    // Indexer implementation
    // ==========================================

    public function upsert(string $physicalName, iterable $documents): UpsertReport
    {
        $start = hrtime(true);
        $physical = $this->resolveAlias($physicalName) ?? $physicalName;
        if (!isset($this->indices[$physical])) {
            $this->indices[$physical] = [];
        }

        $indexed = 0;
        foreach ($documents as $doc) {
            if (isset($this->indices[$physical][$doc->id])) {
                $existing = $this->indices[$physical][$doc->id];
                // Monotonic version check
                if ($doc->version < $existing->version) {
                    continue;
                }
            }
            $this->indices[$physical][$doc->id] = $doc;
            $indexed++;
        }

        $durationMs = (hrtime(true) - $start) / 1e6;

        return new UpsertReport(indexedCount: $indexed, durationMs: $durationMs);
    }

    public function delete(string $physicalName, array $ids): void
    {
        $physical = $this->resolveAlias($physicalName) ?? $physicalName;
        if (!isset($this->indices[$physical])) {
            return;
        }

        foreach ($ids as $id) {
            unset($this->indices[$physical][$id]);
        }
    }

    // ==========================================
    // TextRetriever implementation
    // ==========================================

    public function text(RetrievalRequest $request): RetrievalResult
    {
        $physical = $this->resolveAlias($request->index);
        if ($physical === null || !isset($this->indices[$physical])) {
            return new RetrievalResult(hits: [], total: 0);
        }

        $filteredDocs = $this->filterDocuments($this->indices[$physical], $request);

        // Score documents
        $queryTokens = $this->tokenize($request->query);
        $scoredHits = [];

        foreach ($filteredDocs as $doc) {
            $score = 0.0;
            $highlights = [];

            if (empty($queryTokens)) {
                $score = 1.0;
            } else {
                foreach ($doc->fields as $field => $value) {
                    if (!is_string($value) && !is_numeric($value)) {
                        continue;
                    }
                    $strVal = (string) $value;
                    $matched = false;
                    foreach ($queryTokens as $token) {
                        if (stripos($strVal, $token) !== false) {
                            $score += (float) ($request->options['weights'][$field] ?? 1.0);
                            $matched = true;
                        }
                    }
                    if ($matched) {
                        $highlights[$field] = $strVal;
                    }
                }
            }

            if ($score > 0.0) {
                $scoredHits[] = new Hit(
                    id: $doc->id,
                    score: $score,
                    fields: $doc->fields,
                    highlights: $highlights,
                    source: 'text',
                );
            }
        }

        // Compute facets before pagination
        $facets = $this->computeFacets($filteredDocs, $request->facets);

        // Sort hits
        $sortedHits = $this->sortHits($scoredHits, $request->sort);

        $total = count($sortedHits);
        $page = array_slice($sortedHits, $request->offset, $request->limit);

        return new RetrievalResult(hits: $page, total: $total, facets: $facets);
    }

    // ==========================================
    // VectorRetriever implementation
    // ==========================================

    public function vector(RetrievalRequest $request, array $embedding): RetrievalResult
    {
        $physical = $this->resolveAlias($request->index);
        if ($physical === null || !isset($this->indices[$physical])) {
            return new RetrievalResult(hits: [], total: 0);
        }

        $filteredDocs = $this->filterDocuments($this->indices[$physical], $request);
        $scoredHits = [];

        foreach ($filteredDocs as $doc) {
            $rawVector = $doc->fields['embedding'] ?? $doc->vectorInputs['embedding'] ?? null;
            if (!is_array($rawVector) || empty($rawVector)) {
                continue;
            }

            /** @var list<float> $docVector */
            $docVector = array_values(array_map('floatval', $rawVector));

            $sim = $this->cosineSimilarity($embedding, $docVector);
            if ($sim > 0.0) {
                $scoredHits[] = new Hit(
                    id: $doc->id,
                    score: $sim,
                    fields: $doc->fields,
                    source: 'vector',
                );
            }
        }

        $facets = $this->computeFacets($filteredDocs, $request->facets);
        $sortedHits = $this->sortHits($scoredHits, $request->sort);

        $total = count($sortedHits);
        $page = array_slice($sortedHits, $request->offset, $request->limit);

        return new RetrievalResult(hits: $page, total: $total, facets: $facets);
    }

    // ==========================================
    // HybridRetriever implementation
    // ==========================================

    public function hybrid(RetrievalRequest $request, array $embedding, float $semanticRatio): RetrievalResult
    {
        $textResult = $this->text($request);
        $vectorResult = $this->vector($request, $embedding);

        $fuser = new ReciprocalRankFusion(k: 60);
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

        $page = array_slice($fusedHits, $request->offset, $request->limit);

        return new RetrievalResult(
            hits: $page,
            total: max($textResult->total, $vectorResult->total),
            facets: $textResult->facets,
        );
    }

    // ==========================================
    // Suggester implementation
    // ==========================================

    public function suggest(SuggestRequest $request): SuggestResult
    {
        $physical = $this->resolveAlias($request->index);
        if ($physical === null || !isset($this->indices[$physical])) {
            return new SuggestResult();
        }

        $prefix = mb_strtolower(trim($request->query));
        $queries = [];
        $documents = [];

        foreach ($this->indices[$physical] as $doc) {
            $title = (string) ($doc->fields['title'] ?? $doc->fields['name'] ?? '');
            if ($prefix !== '' && str_starts_with(mb_strtolower($title), $prefix)) {
                $queries[] = $title;
                $documents[] = new Hit(id: $doc->id, score: 1.0, fields: $doc->fields, source: 'suggest');
                if (count($queries) >= $request->limit) {
                    break;
                }
            }
        }

        return new SuggestResult(
            queries: array_values(array_unique($queries)),
            documents: $documents,
        );
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * @param array<string, Document> $docs
     * @return list<Document>
     */
    private function filterDocuments(array $docs, RetrievalRequest $request): array
    {
        $result = [];
        $context = $request->context;

        foreach ($docs as $doc) {
            // Tenant isolation
            $docTenant = $doc->tenant ?? ($doc->fields['tenant_id'] ?? ($doc->fields['tenant'] ?? null));
            if ($context->tenant !== null && $docTenant !== null && $docTenant !== $context->tenant) {
                continue;
            }

            // ACL filtering
            if (!empty($context->acl) && !empty($doc->acl)) {
                if (empty(array_intersect($doc->acl, $context->acl))) {
                    continue;
                }
            }

            // Filter AST
            if ($request->filter !== null && !$this->filterCompiler->matches($request->filter, $doc)) {
                continue;
            }

            $result[] = $doc;
        }

        return $result;
    }

    /**
     * @param list<Hit> $hits
     * @param array<string, 'asc'|'desc'> $sort
     * @return list<Hit>
     */
    private function sortHits(array $hits, array $sort): array
    {
        if (empty($sort)) {
            usort($hits, static fn (Hit $a, Hit $b) => ($b->score <=> $a->score) ?: strcmp($a->id, $b->id));
            return $hits;
        }

        usort($hits, static function (Hit $a, Hit $b) use ($sort) {
            foreach ($sort as $field => $dir) {
                $valA = $a->fields[$field] ?? null;
                $valB = $b->fields[$field] ?? null;
                $cmp = $valA <=> $valB;
                if ($cmp !== 0) {
                    return strtolower($dir) === 'desc' ? -$cmp : $cmp;
                }
            }

            return ($b->score <=> $a->score) ?: strcmp($a->id, $b->id);
        });

        return $hits;
    }

    /**
     * @param list<Document> $docs
     * @param list<string> $facetFields
     * @return array<string, array<string, int>>
     */
    private function computeFacets(array $docs, array $facetFields): array
    {
        $facets = [];
        foreach ($facetFields as $field) {
            $counts = [];
            foreach ($docs as $doc) {
                $val = $doc->fields[$field] ?? null;
                if (is_array($val)) {
                    foreach ($val as $item) {
                        $key = (string) $item;
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                } elseif ($val !== null) {
                    $key = (string) $val;
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
            arsort($counts);
            $facets[$field] = $counts;
        }

        return $facets;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower(trim($query))) ?? '';
        $tokens = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens !== false ? $tokens : [];
    }
}
