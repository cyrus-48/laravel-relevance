<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Relevance\Contracts\CapabilitySet;
use Relevance\Contracts\Driver;
use Relevance\Contracts\Indexer;
use Relevance\Data\RetrievalRequest;
use Relevance\Data\UpsertReport;
use Relevance\Drivers\DriverManager;
use Relevance\Drivers\Memory\MemoryDriver;
use Relevance\Indexing\Outbox;
use Relevance\Indexing\OutboxDispatcher;
use Relevance\Jobs\DrainOutbox;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;
use Relevance\Schema\Schema;
use Relevance\Tests\TestCase;
use RuntimeException;

class ResilienceItemIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'resilience_items';
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('name');
    }
}

/**
 * Wraps the memory driver but rejects any batch that contains the document id "bad".
 */
final class PoisonAwareIndexer implements Driver, Indexer
{
    public function __construct(private readonly MemoryDriver $inner) {}

    public function name(): string
    {
        return 'poison-aware';
    }

    public function capabilities(): CapabilitySet
    {
        return $this->inner->capabilities();
    }

    public function upsert(string $physicalName, iterable $documents): UpsertReport
    {
        $documents = is_array($documents) ? $documents : iterator_to_array($documents, false);
        foreach ($documents as $doc) {
            if ($doc->id === 'bad') {
                throw new RuntimeException('engine rejected document "bad"');
            }
        }

        return $this->inner->upsert($physicalName, $documents);
    }

    public function delete(string $physicalName, array $ids): void
    {
        $this->inner->delete($physicalName, $ids);
    }

    public function inner(): MemoryDriver
    {
        return $this->inner;
    }
}

class OutboxResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        app(IndexRegistry::class)->register(new ResilienceItemIndex());
    }

    private function pending(string $docId, int $attempts = 0, string $status = 'pending'): Outbox
    {
        return Outbox::create([
            'index'   => 'resilience_items',
            'doc_id'  => $docId,
            'op'      => 'upsert',
            'version' => 1,
            'payload' => ['id' => $docId, 'name' => "item {$docId}"],
            'attempts' => $attempts,
            'status'  => $status,
        ]);
    }

    public function test_one_run_drains_more_rows_than_a_single_batch(): void
    {
        config(['relevance.queue.batch_size' => 2]);

        foreach (['1', '2', '3', '4', '5'] as $id) {
            $this->pending($id);
        }

        (new DrainOutbox('resilience_items'))->handle(app(OutboxDispatcher::class));

        $this->assertSame(0, Outbox::pending()->count());
        $this->assertSame(5, Outbox::where('status', 'done')->count());
    }

    public function test_rows_stuck_in_processing_are_reclaimed_and_drained(): void
    {
        config(['relevance.queue.visibility_timeout' => 60]);

        $row = $this->pending('stuck', attempts: 1, status: 'processing');
        DB::table('relevance_outbox')->where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

        (new DrainOutbox('resilience_items'))->handle(app(OutboxDispatcher::class));

        $this->assertSame('done', Outbox::find($row->id)?->status);
    }

    public function test_recently_claimed_processing_rows_are_left_alone(): void
    {
        config(['relevance.queue.visibility_timeout' => 300]);

        $row = $this->pending('in-flight', attempts: 1, status: 'processing');

        (new DrainOutbox('resilience_items'))->handle(app(OutboxDispatcher::class));

        $this->assertSame('processing', Outbox::find($row->id)?->status);
    }

    public function test_stale_rows_with_exhausted_attempts_are_dead_lettered_not_retried(): void
    {
        config(['relevance.queue.visibility_timeout' => 60, 'relevance.queue.max_attempts' => 3]);

        $row = $this->pending('crashy', attempts: 3, status: 'processing');
        DB::table('relevance_outbox')->where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

        (new DrainOutbox('resilience_items'))->handle(app(OutboxDispatcher::class));

        $this->assertSame('dead', Outbox::find($row->id)?->status);
    }

    public function test_a_poison_document_does_not_block_the_rest_of_the_batch(): void
    {
        $wrapper = new PoisonAwareIndexer(new MemoryDriver());
        app(DriverManager::class)->set('memory', $wrapper);

        $good1 = $this->pending('good-1');
        $bad = $this->pending('bad');
        $good2 = $this->pending('good-2');

        (new DrainOutbox('resilience_items'))->handle(app(OutboxDispatcher::class));

        $this->assertSame('done', Outbox::find($good1->id)?->status);
        $this->assertSame('done', Outbox::find($good2->id)?->status);

        $failed = Outbox::find($bad->id);
        $this->assertSame('pending', $failed?->status, 'the poison row is retried later, not dropped');
        $this->assertStringContainsString('rejected document', (string) $failed?->last_error);

        // The healthy documents really reached the index.
        $hits = $wrapper->inner()->text(new RetrievalRequest(index: 'resilience_items', query: 'item'))->hits;
        $this->assertEqualsCanonicalizing(['good-1', 'good-2'], array_map(static fn ($h) => $h->id, $hits));
    }

    public function test_package_registers_scheduled_drain_and_prune(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $this->assertTrue(
            $events->contains(fn ($e) => str_contains((string) $e->description, 'DrainOutbox')),
            'DrainOutbox should be scheduled',
        );
        $this->assertTrue(
            $events->contains(fn ($e) => str_contains((string) $e->command, 'relevance:prune')),
            'relevance:prune should be scheduled',
        );
    }
}
