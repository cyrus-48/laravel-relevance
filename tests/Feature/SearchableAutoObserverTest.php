<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema as DbSchema;
use Relevance\Concerns\Searchable;
use Relevance\Indexing\Outbox;
use Relevance\Jobs\DrainOutbox;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;
use Relevance\Schema\Schema;
use Relevance\Tests\TestCase;

class AutoObservedWidget extends Model
{
    use Searchable;

    protected $table = 'auto_observed_widgets';
    protected $guarded = [];

    public static function relevanceIndex(): string
    {
        return AutoObservedWidgetIndex::class;
    }
}

class AutoObservedWidgetIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'auto_observed_widgets';
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('name');
    }

    public function toDocument(mixed $item): array
    {
        /** @var AutoObservedWidget $item */
        return ['id' => (string) $item->getKey(), 'name' => $item->name];
    }
}

/**
 * A model that only uses the Searchable trait (no registerModel() call, no config entry)
 * must still be indexed. Previously the observer was attached only for models registered
 * before the service provider booted, so trait-only models were silently never indexed.
 */
class SearchableAutoObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        DbSchema::create('auto_observed_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Queue::fake([DrainOutbox::class]);
        Model::clearBootedModels();
    }

    public function test_trait_only_model_writes_exactly_one_outbox_row_per_save(): void
    {
        $widget = AutoObservedWidget::create(['name' => 'Sprocket']);

        $this->assertSame(1, Outbox::where('index', 'auto_observed_widgets')
            ->where('doc_id', (string) $widget->getKey())
            ->where('op', 'upsert')
            ->count());
    }

    public function test_registering_observers_again_does_not_duplicate_outbox_rows(): void
    {
        /** @var IndexRegistry $registry */
        $registry = app(IndexRegistry::class);
        $registry->registerModel(AutoObservedWidget::class, AutoObservedWidgetIndex::class);
        $registry->registerObservers();
        $registry->registerObservers();
        $registry->observeModel(AutoObservedWidget::class);

        $widget = AutoObservedWidget::create(['name' => 'Gizmo']);

        $this->assertSame(1, Outbox::where('doc_id', (string) $widget->getKey())->count());
    }

    public function test_delete_enqueues_a_delete_row(): void
    {
        $widget = AutoObservedWidget::create(['name' => 'Doomed']);
        $widget->delete();

        $this->assertSame(1, Outbox::where('doc_id', (string) $widget->getKey())->where('op', 'delete')->count());
    }
}
