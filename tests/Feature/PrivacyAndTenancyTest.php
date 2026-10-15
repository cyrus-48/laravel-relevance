<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Relevance\Data\Document;
use Relevance\Data\IndexSchema;
use Relevance\Events\UserPrivacyErased;
use Relevance\Exceptions\MissingTenantScopeException;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;

class PrivacyAndTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $driver = Relevance::driver('memory');
        $driver->create(new IndexSchema('tenant_products'), 'tenant_products_test');
        $driver->swapAlias('tenant_products', 'tenant_products_test');

        $driver->upsert('tenant_products', [
            new Document(id: 'p1', version: 1, fields: ['title' => 'T1 Item', 'tenant_id' => 'tenant_a']),
            new Document(id: 'p2', version: 1, fields: ['title' => 'T2 Item', 'tenant_id' => 'tenant_b']),
        ]);
    }

    public function test_gdpr_privacy_erase_removes_user_data(): void
    {
        Event::fake([UserPrivacyErased::class]);

        // Seed user interactions & search logs
        DB::table('relevance_interactions')->insert([
            ['index' => 'tenant_products', 'user_ref' => 'user_123', 'item_id' => 'p1', 'type' => 'click', 'weight' => 1.0, 'occurred_at' => now()],
            ['index' => 'tenant_products', 'user_ref' => 'user_999', 'item_id' => 'p2', 'type' => 'click', 'weight' => 1.0, 'occurred_at' => now()],
        ]);

        DB::table('relevance_search_logs')->insert([
            ['search_id' => 'srch_123', 'index' => 'tenant_products', 'query_norm' => 'item', 'query_hash' => 'hash1', 'user_ref' => 'user_123'],
        ]);

        $report = Relevance::privacy()->erase('user_123');

        $this->assertSame('user_123', $report->userRef);
        $this->assertSame(2, $report->totalDeleted);

        // user_123 records are completely gone
        $this->assertSame(0, DB::table('relevance_interactions')->where('user_ref', 'user_123')->count());
        $this->assertSame(0, DB::table('relevance_search_logs')->where('user_ref', 'user_123')->count());

        // other user records untouched
        $this->assertSame(1, DB::table('relevance_interactions')->where('user_ref', 'user_999')->count());

        Event::assertDispatched(UserPrivacyErased::class);
    }

    public function test_gdpr_privacy_export_returns_user_data(): void
    {
        DB::table('relevance_interactions')->insert([
            ['index' => 'tenant_products', 'user_ref' => 'user_export', 'item_id' => 'p1', 'type' => 'view', 'weight' => 1.0, 'occurred_at' => now()],
        ]);

        $export = Relevance::privacy()->export('user_export');

        $this->assertArrayHasKey('interactions', $export);
        $this->assertCount(1, $export['interactions']);
        $this->assertSame('p1', $export['interactions'][0]['item_id']);
    }

    public function test_relevance_prune_command_deletes_expired_records(): void
    {
        // 1 old interaction (400 days ago) and 1 fresh interaction
        DB::table('relevance_interactions')->insert([
            ['index' => 'tenant_products', 'user_ref' => 'user_old', 'item_id' => 'p1', 'type' => 'click', 'weight' => 1.0, 'occurred_at' => Carbon::now()->subDays(410)],
            ['index' => 'tenant_products', 'user_ref' => 'user_fresh', 'item_id' => 'p1', 'type' => 'click', 'weight' => 1.0, 'occurred_at' => Carbon::now()->subDays(5)],
        ]);

        $this->assertSame(2, DB::table('relevance_interactions')->count());

        $exitCode = $this->artisan('relevance:prune', [
            '--interactions-days' => 395,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('relevance_interactions')->count());
        $this->assertSame('user_fresh', DB::table('relevance_interactions')->first()?->user_ref);
    }

    public function test_relevance_privacy_erase_artisan_command(): void
    {
        DB::table('relevance_interactions')->insert([
            ['index' => 'tenant_products', 'user_ref' => 'user_cli', 'item_id' => 'p1', 'type' => 'click', 'weight' => 1.0, 'occurred_at' => now()],
        ]);

        $exitCode = $this->artisan('relevance:privacy:erase', [
            'user' => 'user_cli',
            '--force' => true,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('relevance_interactions')->where('user_ref', 'user_cli')->count());
    }

    public function test_tenancy_fail_closed_enforcement(): void
    {
        config(['relevance.tenancy.required' => true]);

        // 1. Without tenant scope -> throws MissingTenantScopeException
        $this->expectException(MissingTenantScopeException::class);
        Relevance::index('tenant_products')->search('Item')->get();
    }

    public function test_tenancy_scoped_search_and_bypass(): void
    {
        config(['relevance.tenancy.required' => true]);

        // 2. With tenant scope -> succeeds and isolates to tenant
        $resTenantA = Relevance::index('tenant_products')
            ->tenant('tenant_a')
            ->search('Item')
            ->get();

        $this->assertNotEmpty($resTenantA->hits);
        foreach ($resTenantA->hits as $hit) {
            $this->assertSame('tenant_a', $hit->fields['tenant_id']);
        }

        // 3. Admin bypass with explicit audited reason -> succeeds
        $resAll = Relevance::index('tenant_products')
            ->withoutScopes('admin reindex audit')
            ->search('Item')
            ->get();

        $this->assertCount(2, $resAll->hits);
    }
}
