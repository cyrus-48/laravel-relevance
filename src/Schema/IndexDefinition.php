<?php

declare(strict_types=1);

namespace Relevance\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relevance\Data\IndexSchema;
use Relevance\Support\Hasher;

abstract class IndexDefinition
{
    private ?IndexSchema $compiled = null;

    abstract public function name(): string;

    /**
     * Source query for bulk import/rebuild (eager-loads everything toDocument touches).
     *
     * @return Builder<Model>|null
     */
    public function source(): ?Builder
    {
        return null;
    }

    /**
     * Iterable source for non-Eloquent documents.
     *
     * @return iterable<mixed>
     */
    public function documents(): iterable
    {
        return [];
    }

    abstract public function schema(Schema $s): void;

    /**
     * Transform a model or raw entity to a document payload.
     *
     * @return array<string, mixed>
     */
    public function toDocument(mixed $item): array
    {
        if ($item instanceof Model) {
            return $item->toArray();
        }

        return (array) $item;
    }

    /**
     * Determine if the model/entity should be indexed.
     */
    public function shouldIndex(mixed $item): bool
    {
        if ($item instanceof Model) {
            if (method_exists($item, 'trashed') && $item->trashed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Related models whose changes trigger re-indexing.
     *
     * @return array<class-string<Model>, callable(Model): (Builder<Model>|iterable<Model>)>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * Monotonic version used for ordering writes; defaults to updated_at in microseconds.
     */
    public function version(mixed $item): int
    {
        if ($item instanceof Model) {
            $updatedAt = $item->getAttribute('updated_at');
            if ($updatedAt instanceof \DateTimeInterface) {
                return (int) ($updatedAt->format('Uu'));
            }
        }

        return (int) (microtime(true) * 1_000_000);
    }

    public function tenantOf(mixed $item): ?string
    {
        if ($item instanceof Model) {
            $tenantId = $item->getAttribute('tenant_id');
            return $tenantId !== null ? (string) $tenantId : null;
        }

        if (is_array($item) && isset($item['tenant_id'])) {
            return (string) $item['tenant_id'];
        }

        return null;
    }

    public function compiledSchema(): IndexSchema
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $schema = new Schema();
        $this->schema($schema);

        return $this->compiled = $schema->compile($this->name());
    }

    public function schemaHash(): string
    {
        return Hasher::hash($this->compiledSchema()->fields);
    }
}
