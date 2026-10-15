<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Contracts\Driver;
use Relevance\Data\Document;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;

class RelevanceFeatureTest extends TestCase
{
    public function test_facade_resolves_driver(): void
    {
        $driver = Relevance::driver();
        $this->assertInstanceOf(Driver::class, $driver);
        $this->assertSame('memory', $driver->name());
    }

    public function test_fake_allows_seeding_and_assertions(): void
    {
        $fake = Relevance::fake();
        $this->assertTrue(Relevance::isFaked());

        $fake->seed('products', [
            ['id' => '101', 'title' => 'Mechanical Keyboard', 'price' => 150],
            new Document(id: '102', fields: ['title' => 'Ergonomic Mouse', 'price' => 80]),
        ]);

        Relevance::assertIndexed('products', function ($fields) {
            return ($fields['title'] ?? '') === 'Mechanical Keyboard';
        });

        $response = Relevance::search('Keyboard', index: 'products');
        $this->assertCount(1, $response->hits);
        $this->assertSame('101', $response->hits[0]->id);

        Relevance::assertSearched('products', 'Keyboard');

        Relevance::fake()->track('click', '101');
        Relevance::assertTracked('click', '101');
    }

    public function test_relevance_doctor_artisan_command(): void
    {
        $this->artisan('relevance:doctor')
            ->expectsOutputToContain('Default Search Driver: memory')
            ->expectsOutputToContain('Driver Capability Matrix')
            ->expectsOutputToContain('Relevance Doctor inspection passed with 0 errors.')
            ->assertExitCode(0);
    }
}
