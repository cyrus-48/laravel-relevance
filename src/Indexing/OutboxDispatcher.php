<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Support\Collection;
use Relevance\Contracts\Indexer;
use Relevance\Data\Document;
use Relevance\Drivers\DriverManager;
use Relevance\Embeddings\EmbeddingInputBuilder;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Schema\IndexRegistry;
use Throwable;

class OutboxDispatcher
{
    public function __construct(
        private readonly DriverManager $driverManager,
        private readonly GenerationManager $generationManager,
        private readonly IndexRegistry $registry,
        private readonly ?EmbeddingManager $embeddingManager = null,
        private readonly ?EmbeddingInputBuilder $inputBuilder = null,
    ) {}

    public function registry(): IndexRegistry
    {
        return $this->registry;
    }

    /**
     * @param Collection<int, Outbox> $rows
     */
    public function dispatch(Collection $rows): void
    {
        $driver = $this->driverManager->driver();
        if (!$driver instanceof Indexer) {
            throw new \RuntimeException("Current driver does not support indexing.");
        }

        /** @var Collection<string, Collection<int, Outbox>> $byIndex */
        $byIndex = $rows->groupBy('index');

        foreach ($byIndex as $indexName => $indexRows) {
            try {
                $this->processIndexBatch($driver, $indexName, $indexRows);
                foreach ($indexRows as $row) {
                    $row->markDone();
                }
            } catch (Throwable $e) {
                $this->retryPerDocument($driver, $indexName, $indexRows, $e);
            }
        }
    }

    /**
     * A batch failed. Re-process it one document at a time so a single poison document
     * only fails (and eventually dead-letters) its own rows instead of blocking every
     * other document in the batch. Writes are idempotent, so re-applying is safe.
     *
     * @param Collection<int, Outbox> $indexRows
     */
    private function retryPerDocument(Indexer $driver, string $indexName, Collection $indexRows, Throwable $batchError): void
    {
        $maxAttempts = (int) config('relevance.queue.max_attempts', 5);

        /** @var Collection<string, Collection<int, Outbox>> $byDoc */
        $byDoc = $indexRows->groupBy('doc_id');

        if ($byDoc->count() === 1) {
            foreach ($indexRows as $row) {
                $row->markFailed($batchError->getMessage(), $maxAttempts);
            }

            return;
        }

        foreach ($byDoc as $docRows) {
            try {
                $this->processIndexBatch($driver, $indexName, $docRows);
                foreach ($docRows as $row) {
                    $row->markDone();
                }
            } catch (Throwable $e) {
                foreach ($docRows as $row) {
                    $row->markFailed($e->getMessage(), $maxAttempts);
                }
            }
        }
    }

    /**
     * @param Collection<int, Outbox> $indexRows
     */
    private function processIndexBatch(Indexer $indexer, string $indexName, Collection $indexRows): void
    {
        /** @var Collection<string, Outbox> $coalesced */
        $coalesced = $indexRows->groupBy('doc_id')->map(function (Collection $group): Outbox {
            /** @var Outbox $last */
            $last = $group->last();
            return $last;
        });

        $upsertRows = $coalesced->where('op', 'upsert');
        $deleteRows = $coalesced->where('op', 'delete');

        $targets = $this->generationManager->targetPhysicalNames($indexName);
        $def = $this->registry->get($indexName);

        // Compute embeddings if index has a vector field
        $vectorField = $def?->vectorFieldName();
        $embeddings = [];
        if ($def !== null && $vectorField !== null && $this->embeddingManager !== null) {
            $builder = $this->inputBuilder ?? new EmbeddingInputBuilder();
            $texts = [];
            $rowKeys = [];
            foreach ($upsertRows as $row) {
                $texts[] = $builder->build($def, $row->payload ?? []);
                $rowKeys[] = $row->doc_id;
            }
            $vectors = $this->embeddingManager->embedMany($texts);
            foreach ($rowKeys as $i => $id) {
                $embeddings[$id] = $vectors[$i] ?? [];
            }
        }

        // Prepare documents for upsert
        $documents = [];
        foreach ($upsertRows as $row) {
            $fields = $row->payload ?? [];
            $vectorInputs = [];
            if ($vectorField !== null && !empty($embeddings[$row->doc_id])) {
                $fields[$vectorField] = $embeddings[$row->doc_id];
                $vectorInputs[$vectorField] = $embeddings[$row->doc_id];
            }

            $documents[] = new Document(
                id: $row->doc_id,
                version: $row->version,
                fields: $fields,
                vectorInputs: $vectorInputs,
                tenant: $row->tenant_id,
            );
        }

        $deleteIds = array_values(array_map('strval', $deleteRows->pluck('doc_id')->all()));

        // Write to all target generations (dual-write if rebuilding)
        foreach ($targets as $targetPhysical) {
            if (!empty($documents)) {
                $indexer->upsert($targetPhysical, $documents);
            }
            if (!empty($deleteIds)) {
                $indexer->delete($targetPhysical, $deleteIds);
            }
        }
    }
}
