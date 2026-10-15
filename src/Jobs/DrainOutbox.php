<?php

declare(strict_types=1);

namespace Relevance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Relevance\Indexing\Outbox;
use Relevance\Indexing\OutboxDispatcher;

class DrainOutbox implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly ?string $index = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->index ?? 'all';
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $batchSize = (int) config('relevance.queue.batch_size', 500);

        $query = Outbox::pending()->orderBy('id')->limit($batchSize);
        if ($this->index !== null) {
            $query->where('index', $this->index);
        }

        $rows = DB::transaction(function () use ($query) {
            $driverName = DB::connection()->getDriverName();
            if ($driverName !== 'sqlite') {
                $query->lockForUpdate();
                if (method_exists($query, 'skipLocked')) {
                    $query->skipLocked();
                }
            }

            $pending = $query->get();
            foreach ($pending as $row) {
                $row->markProcessing();
            }

            return $pending;
        });

        if ($rows->isEmpty()) {
            return;
        }

        $dispatcher->dispatch($rows);
    }
}
