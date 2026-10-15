<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relevance\Data\Hit;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Facades\Relevance;
use Relevance\Tests\TestCase;

class TrackableItemModel extends Model
{
    protected $guarded = [];
}

class TrackingAndAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
    }

    public function test_tracker_records_interactions_to_database(): void
    {
        $item = new TrackableItemModel(['id' => 42]);

        Relevance::track('user_123')
            ->click($item, searchId: 'srch_abc', position: 2, value: 19.99, index: 'products');

        $row = DB::table('relevance_interactions')->where('item_id', '42')->first();

        $this->assertNotNull($row);
        $this->assertSame('products', $row->index);
        $this->assertSame('user_123', $row->user_ref);
        $this->assertSame('click', $row->type);
        $this->assertSame('srch_abc', $row->search_id);
        $this->assertSame(2, $row->position);
        $this->assertEqualsWithDelta(19.99, (float) $row->value, 0.01);
    }

    public function test_alias_merges_anonymous_interactions(): void
    {
        Relevance::track()->anon('anon_session_99')->view('item_77', index: 'catalog');

        $initial = DB::table('relevance_interactions')->where('anon_ref', 'anon_session_99')->first();
        $this->assertNotNull($initial);
        $this->assertNull($initial->user_ref);

        Relevance::alias('anon_session_99', 'user_real_456');

        $updated = DB::table('relevance_interactions')->where('anon_ref', 'anon_session_99')->first();
        $this->assertNotNull($updated);
        $this->assertSame('user_real_456', $updated->user_ref);
    }

    public function test_search_logger_records_searches(): void
    {
        $req = new SearchRequest(index: 'products', query: 'wireless mouse');
        $resp = Relevance::search($req);

        $log = DB::table('relevance_search_logs')->where('search_id', $resp->searchId)->first();
        $this->assertNotNull($log);
        $this->assertSame('wireless mouse', $log->query_norm);
        $this->assertSame('products', $log->index);
        $this->assertNotEmpty($log->query_hash);
    }

    public function test_faked_tracking_assertions(): void
    {
        Relevance::fake();

        $product = new TrackableItemModel(['id' => 99]);
        Relevance::track('usr_1')->purchase($product, value: 99.0);

        Relevance::assertTracked('purchase', $product);
    }
}
