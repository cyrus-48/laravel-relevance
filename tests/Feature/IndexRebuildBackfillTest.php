<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DbSchema;
use Relevance\Contracts\EmbeddingProvider;
use Relevance\Contracts\TextRetriever;
use Relevance\Data\EmbedKind;
use Relevance\Data\RetrievalRequest;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Facades\Relevance;
use Relevance\Indexing\IndexBackfiller;
use Relevance\Indexing\IndexGeneration;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;
use Relevance\Schema\Schema;
use Relevance\Tests\TestCase;
use RuntimeException;

class RebuildGadget extends Model
{
    protected $table = 'rebuild_gadgets';
    protected $guarded = [];
}

class RebuildGadgetIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'rebuild_gadgets';
    }

    public function source(): ?Builder
    {
        return RebuildGadget::query();
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('title');
        $s->vector('embedding')->from(['title']);
    }

    public function toDocument(mixed $item): array
    {
        /** @var RebuildGadget $item */
        return ['id' => (string) $item->getKey(), 'title' => $item->title];
    }
}

class ThrowingEmbeddingProvider implements EmbeddingProvider
{
    public function fingerprint(): string
    {
        return 'throwing:v1';
    }

    public function dimensions(): int
    {
        return 8;
    }

    public function embed(array $texts, EmbedKind $kind = EmbedKind::Document): array
    {
        throw new RuntimeException('embedding provider is down');
    }
}

class IndexRebuildBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        DbSchema::create('rebuild_gadgets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        foreach (['Alpha widget', 'Beta widget', 'Gamma widget'] as $title) {
            RebuildGadget::create(['title' => $title]);
        }

        app(IndexRegistry::class)->register(new RebuildGadgetIndex());
    }

    public function test_backfill_embeds_every_document(): void
    {
        $report = app(IndexBackfiller::class)->backfill(new RebuildGadgetIndex(), 'rebuild_gadgets_manual');

        $this->assertSame(3, $report->documents);
        $this->assertSame(3, $report->embedded);
        $this->assertTrue($report->isComplete());

        /** @var TextRetriever $driver */
        $driver = Relevance::driver();
        $hits = $driver->text(new RetrievalRequest(index: 'rebuild_gadgets_manual', query: ''))->hits;

        $this->assertCount(3, $hits);
        foreach ($hits as $hit) {
            $this->assertNotEmpty($hit->fields['embedding'] ?? null, 'every backfilled document must carry a vector');
        }
    }

    public function test_auto_rebuild_activates_a_complete_generation(): void
    {
        $this->artisan('relevance:index:rebuild', ['index' => 'rebuild_gadgets', '--activate' => 'auto'])
            ->expectsOutputToContain('Embedded 3/3 documents')
            ->expectsOutputToContain('Active alias [rebuild_gadgets] now points to rebuild_gadgets_g1.')
            ->assertExitCode(0);

        $this->assertSame('active', IndexGeneration::forIndex('rebuild_gadgets')->where('generation', 1)->first()?->state);
    }

    public function test_auto_rebuild_refuses_to_activate_when_embeddings_fail(): void
    {
        app(EmbeddingManager::class)->set('fake', new ThrowingEmbeddingProvider());

        $this->artisan('relevance:index:rebuild', ['index' => 'rebuild_gadgets', '--activate' => 'auto'])
            ->expectsOutputToContain('Not activating generation #1')
            ->assertExitCode(1);

        // The generation exists and holds the documents, but the live alias was not swapped.
        $this->assertSame('building', IndexGeneration::forIndex('rebuild_gadgets')->where('generation', 1)->first()?->state);
        $this->assertNull(IndexGeneration::forIndex('rebuild_gadgets')->active()->first());
    }
}
