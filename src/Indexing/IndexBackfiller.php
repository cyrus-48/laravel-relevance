<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Database\Eloquent\Model;
use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Drivers\DriverManager;
use Relevance\Embeddings\EmbeddingInputBuilder;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Schema\IndexDefinition;
use RuntimeException;
use Throwable;

/**
 * Bulk-loads a physical index generation from an IndexDefinition's source.
 *
 * Unlike the incremental outbox path, a rebuild must produce the *same* documents the
 * outbox would: including vectors. Embeddings are generated in batches (and served from
 * the embedding cache where possible), so a rebuild only pays for changed text.
 */
class IndexBackfiller
{
    public function __construct(
        private readonly DriverManager $driverManager,
        private readonly ?EmbeddingManager $embeddings = null,
        private readonly ?EmbeddingInputBuilder $inputBuilder = null,
    ) {}

    public function backfill(IndexDefinition $def, string $physicalName, int $chunkSize = 200): BackfillReport
    {
        $driver = $this->driverManager->driver();
        if (!$driver instanceof Indexer) {
            throw new RuntimeException("Driver [{$driver->name()}] does not support indexing.");
        }

        $vectorField = $def->vectorFieldName();
        $state = ['documents' => 0, 'embedded' => 0, 'failures' => 0];

        $flush = function (array $batch) use ($def, $driver, $physicalName, $vectorField, &$state): void {
            /** @var list<Document> $batch */
            if ($batch === []) {
                return;
            }

            if ($vectorField !== null) {
                $batch = $this->withEmbeddings($def, $batch, $vectorField, $state);
            }

            $driver->upsert($physicalName, $batch);
            $state['documents'] += count($batch);
        };

        $source = $def->source();
        if ($source !== null) {
            $source->chunkById($chunkSize, function ($models) use ($def, $flush): void {
                $batch = [];
                foreach ($models as $model) {
                    if (!$def->shouldIndex($model)) {
                        continue;
                    }
                    $batch[] = $this->toDocument($def, $model);
                }
                $flush($batch);
            });
        } else {
            $batch = [];
            foreach ($def->documents() as $item) {
                if ($item instanceof Document) {
                    $batch[] = $item;
                } elseif (is_array($item) && isset($item['id'])) {
                    $batch[] = new Document(
                        id: (string) $item['id'],
                        version: $def->version($item),
                        fields: $item,
                        tenant: $def->tenantOf($item),
                    );
                }

                if (count($batch) >= $chunkSize) {
                    $flush($batch);
                    $batch = [];
                }
            }
            $flush($batch);
        }

        return new BackfillReport(
            documents: $state['documents'],
            embedded: $state['embedded'],
            embeddingFailures: $state['failures'],
            expectsVectors: $vectorField !== null,
        );
    }

    private function toDocument(IndexDefinition $def, Model $model): Document
    {
        return new Document(
            id: (string) $model->getKey(),
            version: $def->version($model),
            fields: $def->toDocument($model),
            tenant: $def->tenantOf($model),
        );
    }

    /**
     * @param list<Document> $batch
     * @param array{documents: int, embedded: int, failures: int} $state
     * @return list<Document>
     */
    private function withEmbeddings(IndexDefinition $def, array $batch, string $vectorField, array &$state): array
    {
        if ($this->embeddings === null) {
            $state['failures'] += count($batch);

            return $batch;
        }

        $builder = $this->inputBuilder ?? new EmbeddingInputBuilder();
        $texts = array_map(static fn (Document $d): string => $builder->build($def, $d->fields), $batch);

        try {
            $vectors = $this->embeddings->embedMany($texts);
        } catch (Throwable) {
            // Keep the documents (text search still works) but record the gap so the
            // caller can refuse to auto-activate this generation.
            $state['failures'] += count($batch);

            return $batch;
        }

        $out = [];
        foreach ($batch as $i => $doc) {
            $vector = $vectors[$i] ?? [];
            if ($vector === []) {
                $state['failures']++;
                $out[] = $doc;
                continue;
            }

            $state['embedded']++;
            $fields = $doc->fields;
            $fields[$vectorField] = $vector;
            $out[] = new Document(
                id: $doc->id,
                version: $doc->version,
                fields: $fields,
                vectorInputs: [$vectorField => $vector],
                tenant: $doc->tenant,
                acl: $doc->acl,
            );
        }

        return $out;
    }
}
