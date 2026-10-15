<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\TextRetriever;
use Relevance\Data\RetrievalRequest;
use Relevance\Facades\Relevance;
use Relevance\Indexing\GenerationManager;
use Relevance\Indexing\IndexGeneration;
use Relevance\Indexing\Outbox;
use Relevance\Jobs\DrainOutbox;
use Relevance\Schema\IndexRegistry;
use Relevance\Tests\TestCase;

class GenerationManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        /** @var IndexRegistry $registry */
        $registry = app(IndexRegistry::class);
        $registry->register(new ProductModelIndex());
    }

    public function test_rebuild_and_activate_lifecycle(): void
    {
        /** @var GenerationManager $manager */
        $manager = app(GenerationManager::class);
        $def = new ProductModelIndex();

        // 1. Initial rebuild -> g1
        $gen1 = $manager->rebuild($def);
        $this->assertSame(1, $gen1->generation);
        $this->assertSame('test_products_g1', $gen1->physical_name);
        $this->assertSame('building', $gen1->state);

        // Activate g1
        $manager->activate('test_products', 1);
        $this->assertSame('active', $manager->activeGeneration('test_products')?->state);

        // 2. Rebuild g2 while g1 is active
        $gen2 = $manager->rebuild($def);
        $this->assertSame(2, $gen2->generation);
        $this->assertSame('test_products_g2', $gen2->physical_name);

        // Dual-write check: both g1 and g2 are targets
        $targets = $manager->targetPhysicalNames('test_products');
        $this->assertContains('test_products_g1', $targets);
        $this->assertContains('test_products_g2', $targets);

        // Write to outbox and drain
        Outbox::create([
            'index'   => 'test_products',
            'doc_id'  => '99',
            'op'      => 'upsert',
            'version' => 1,
            'payload' => ['name' => 'Dual Written Product', 'price' => 99.0],
        ]);
        DrainOutbox::dispatchSync('test_products');

        $driver = Relevance::driver();
        $this->assertInstanceOf(TextRetriever::class, $driver);

        // Verify both physical indexes received the document!
        $resG1 = $driver->text(new RetrievalRequest(index: 'test_products_g1', query: 'Dual'));
        $resG2 = $driver->text(new RetrievalRequest(index: 'test_products_g2', query: 'Dual'));
        $this->assertCount(1, $resG1->hits);
        $this->assertCount(1, $resG2->hits);

        // 3. Activate g2
        $manager->activate('test_products', 2);

        $this->assertSame('retired', IndexGeneration::forIndex('test_products')->where('generation', 1)->first()?->state);
        $this->assertSame('active', IndexGeneration::forIndex('test_products')->where('generation', 2)->first()?->state);

        // Alias now resolves to g2
        $resAlias = $driver->text(new RetrievalRequest(index: 'test_products', query: 'Dual'));
        $this->assertCount(1, $resAlias->hits);
    }

    public function test_artisan_rebuild_command(): void
    {
        $this->artisan('relevance:index:rebuild', ['index' => 'test_products', '--activate' => 'auto'])
            ->expectsOutputToContain('Creating new physical generation for index [test_products]...')
            ->expectsOutputToContain('Active alias [test_products] now points to test_products_g1.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('relevance_index_generations', [
            'index'      => 'test_products',
            'generation' => 1,
            'state'      => 'active',
        ]);
    }
}
