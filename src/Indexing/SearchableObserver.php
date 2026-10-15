<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relevance\Jobs\DrainOutbox;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;

final class SearchableObserver
{
    public function __construct(
        private readonly ?IndexRegistry $registry = null,
    ) {}

    public function saved(Model $model): void
    {
        $def = $this->def($model);
        if ($def === null) {
            return;
        }

        $op = $def->shouldIndex($model) ? 'upsert' : 'delete';
        $this->enqueue($model, $def, $op);
    }

    public function deleted(Model $model): void
    {
        $def = $this->def($model);
        if ($def === null) {
            return;
        }

        $this->enqueue($model, $def, 'delete');
    }

    public function restored(Model $model): void
    {
        $this->saved($model);
    }

    private function enqueue(Model $model, IndexDefinition $def, string $op): void
    {
        Outbox::create([
            'index'     => $def->name(),
            'doc_id'    => (string) $model->getKey(),
            'op'        => $op,
            'version'   => $def->version($model),
            'tenant_id' => $def->tenantOf($model),
            'payload'   => $op === 'upsert' ? $def->toDocument($model) : null,
        ]);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static fn () => DrainOutbox::dispatch($def->name()));
        } else {
            DrainOutbox::dispatch($def->name());
        }
    }

    private function def(Model $model): ?IndexDefinition
    {
        $registry = $this->registry ?? app(IndexRegistry::class);
        return $registry->forModel($model);
    }
}
