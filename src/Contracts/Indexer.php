<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\Document;
use Relevance\Data\UpsertReport;

interface Indexer extends Driver
{
    /**
     * @param iterable<Document> $documents
     */
    public function upsert(string $physicalName, iterable $documents): UpsertReport;

    /**
     * @param list<string> $ids
     */
    public function delete(string $physicalName, array $ids): void;
}
