<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DbSchema;
use Relevance\Concerns\Searchable;
use Relevance\Contracts\TextRetriever;
use Relevance\Data\RetrievalRequest;
use Relevance\Facades\Relevance;
use Relevance\Indexing\Outbox;
use Relevance\Jobs\DrainOutbox;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;
use Relevance\Schema\Schema;
use Relevance\Tests\TestCase;

class ProductModel extends Model
{
    use Searchable;

    protected $table = 'test_products_table';
    protected $guarded = [];

    public static function relevanceIndex(): string
    {
        return ProductModelIndex::class;
    }
}

class ProductModelIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'test_products';
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('name')->weight(2.0);
        $s->number('price')->sortable()->filterable();
    }

    public function toDocument(mixed $item): array
    {
        /** @var ProductModel $item */
        return [
            'id'    => (string) $item->id,
            'name'  => $item->name,
            'price' => (float) $item->price,
        ];
    }
}

class OutboxDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        DbSchema::create('test_products_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 8, 2);
            $table->timestamps();
        });

        /** @var IndexRegistry $registry */
        $registry = app(IndexRegistry::class);
        $registry->registerModel(ProductModel::class, ProductModelIndex::class);
        $registry->registerObservers();
    }

    public function test_model_save_writes_to_outbox_and_drains(): void
    {
        \Illuminate\Support\Facades\Queue::fake([DrainOutbox::class]);

        $product = ProductModel::create([
            'name'  => 'Noise Canceling Headphones',
            'price' => 299.99,
        ]);

        $this->assertDatabaseHas('relevance_outbox', [
            'index'  => 'test_products',
            'doc_id' => (string) $product->id,
            'op'     => 'upsert',
            'status' => 'pending',
        ]);

        // Drain outbox manually
        (new DrainOutbox('test_products'))->handle(app(\Relevance\Indexing\OutboxDispatcher::class));

        $this->assertDatabaseHas('relevance_outbox', [
            'index'  => 'test_products',
            'doc_id' => (string) $product->id,
            'status' => 'done',
        ]);

        // Verify driver has the document
        $driver = Relevance::driver();
        $this->assertInstanceOf(TextRetriever::class, $driver);

        $res = $driver->text(new RetrievalRequest(index: 'test_products', query: 'Headphones'));
        $this->assertCount(1, $res->hits);
        $this->assertSame((string) $product->id, $res->hits[0]->id);
    }

    public function test_multiple_writes_coalesce_to_latest_operation(): void
    {
        \Illuminate\Support\Facades\Queue::fake([DrainOutbox::class]);

        $product = ProductModel::create([
            'name'  => 'Initial Title',
            'price' => 50.00,
        ]);

        $product->update([
            'name'  => 'Updated Title',
            'price' => 60.00,
        ]);

        // Two rows in outbox for the same doc_id
        $this->assertSame(2, Outbox::where('doc_id', (string) $product->id)->count());

        // Drain outbox
        (new DrainOutbox('test_products'))->handle(app(\Relevance\Indexing\OutboxDispatcher::class));

        // Both rows marked done
        $this->assertSame(0, Outbox::pending()->count());

        $driver = Relevance::driver();
        /** @var TextRetriever $driver */
        $res = $driver->text(new RetrievalRequest(index: 'test_products', query: 'Updated'));
        $this->assertCount(1, $res->hits);
        $this->assertSame('Updated Title', $res->hits[0]->fields['name']);
        $this->assertEquals(60.0, $res->hits[0]->fields['price']);
    }

    public function test_model_delete_enqueues_delete_op_and_removes_from_index(): void
    {
        \Illuminate\Support\Facades\Queue::fake([DrainOutbox::class]);

        $product = ProductModel::create([
            'name'  => 'Disposable Item',
            'price' => 10.00,
        ]);

        (new DrainOutbox('test_products'))->handle(app(\Relevance\Indexing\OutboxDispatcher::class));

        $product->delete();

        $this->assertDatabaseHas('relevance_outbox', [
            'index'  => 'test_products',
            'doc_id' => (string) $product->id,
            'op'     => 'delete',
            'status' => 'pending',
        ]);

        (new DrainOutbox('test_products'))->handle(app(\Relevance\Indexing\OutboxDispatcher::class));

        $driver = Relevance::driver();
        /** @var TextRetriever $driver */
        $res = $driver->text(new RetrievalRequest(index: 'test_products', query: 'Disposable'));
        $this->assertCount(0, $res->hits);
    }
}
