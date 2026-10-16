<?php

declare(strict_types=1);

namespace Relevance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Relevance\Indexing\Outbox;
use Relevance\Indexing\OutboxDispatcher;

/**
 * Drains the transactional outbox into the search engine.
 *
 * Only one drain per index runs at a time (ShouldBeUnique), which keeps writes for a
 * document in commit order. To avoid stranding rows, a single run keeps claiming batches
 * until nothing is pending (bounded by `queue.max_batches_per_job`), and the scheduler
 * re-dispatches the job every minute as a safety net.
 */
class DrainOutbox implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Release the uniqueness lock after this many seconds even if the worker died,
     * so a crashed worker cannot block draining forever.
     */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly ?string $index = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->index ?? 'all';
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $this->reclaimStale();

        $maxBatches = max(1, (int) config('relevance.queue.max_batches_per_job', 50));

        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = $this->claimBatch();

            if ($rows->isEmpty()) {
                return;
            }

            $dispatcher->dispatch($rows);
        }
    }

    /**
     * Rows left in `processing` by a worker that died would otherwise never be retried.
     * Rows that have already used all their attempts are marked dead rather than looping.
     */
    private function reclaimStale(): void
    {
        $timeout = max(1, (int) config('relevance.queue.visibility_timeout', 300));
        $maxAttempts = (int) config('relevance.queue.max_attempts', 8);
        $cutoff = now()->subSeconds($timeout);

        $stale = function () use ($cutoff) {
            $query = Outbox::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', $cutoff);

            return $this->index !== null ? $query->where('index', $this->index) : $query;
        };

        $stale()
            ->where('attempts', '>=', $maxAttempts)
            ->update([
                'status'     => 'dead',
                'last_error' => 'Worker stopped while processing this row (visibility timeout) and attempts are exhausted.',
            ]);

        $stale()
            ->update([
                'status'       => 'pending',
                'available_at' => now(),
            ]);
    }

    /**
     * @return Collection<int, Outbox>
     */
    private function claimBatch(): Collection
    {
        $batchSize = (int) config('relevance.queue.batch_size', 500);

        $query = Outbox::pending()->orderBy('id')->limit($batchSize);
        if ($this->index !== null) {
            $query->where('index', $this->index);
        }

        /** @var Collection<int, Outbox> $rows */
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

        return $rows;
    }
}
