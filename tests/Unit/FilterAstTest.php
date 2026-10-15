<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Relevance\Data\Document;
use Relevance\Drivers\Memory\MemoryFilterCompiler;
use Relevance\Filters\Filter;

class FilterAstTest extends TestCase
{
    private MemoryFilterCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MemoryFilterCompiler();
    }

    public function test_eq_and_in_filters(): void
    {
        $doc = new Document('1', 1, ['brand' => 'Nike', 'tags' => ['shoes', 'running']]);

        $this->assertTrue($this->compiler->matches(Filter::eq('brand', 'Nike'), $doc));
        $this->assertFalse($this->compiler->matches(Filter::eq('brand', 'Adidas'), $doc));

        // Array field eq matches element
        $this->assertTrue($this->compiler->matches(Filter::eq('tags', 'shoes'), $doc));

        // In filter
        $this->assertTrue($this->compiler->matches(Filter::in('brand', ['Nike', 'Puma']), $doc));
        $this->assertFalse($this->compiler->matches(Filter::in('brand', ['Adidas', 'Puma']), $doc));
    }

    public function test_range_filter(): void
    {
        $doc = new Document('1', 1, ['price' => 50.0]);

        $this->assertTrue($this->compiler->matches(Filter::range('price', gte: 20, lte: 60), $doc));
        $this->assertTrue($this->compiler->matches(Filter::range('price', gt: 49.9, lt: 50.1), $doc));
        $this->assertFalse($this->compiler->matches(Filter::range('price', gte: 60), $doc));
        $this->assertFalse($this->compiler->matches(Filter::range('price', lte: 40), $doc));
    }

    public function test_exists_and_not_filters(): void
    {
        $doc = new Document('1', 1, ['in_stock' => true]);

        $this->assertTrue($this->compiler->matches(Filter::exists('in_stock'), $doc));
        $this->assertFalse($this->compiler->matches(Filter::exists('out_of_stock'), $doc));

        $this->assertTrue($this->compiler->matches(Filter::not(Filter::exists('out_of_stock')), $doc));
        $this->assertFalse($this->compiler->matches(Filter::not(Filter::exists('in_stock')), $doc));
    }

    public function test_boolean_all_and_any_composites(): void
    {
        $doc = new Document('1', 1, ['status' => 'active', 'score' => 85]);

        $allFilter = Filter::all(
            Filter::eq('status', 'active'),
            Filter::range('score', gte: 80),
        );
        $this->assertTrue($this->compiler->matches($allFilter, $doc));

        $anyFilter = Filter::any(
            Filter::eq('status', 'archived'),
            Filter::range('score', gte: 80),
        );
        $this->assertTrue($this->compiler->matches($anyFilter, $doc));
    }

    public function test_geo_radius_filter(): void
    {
        // Central London: lat 51.5074, lng -0.1278
        $docNear = new Document('1', 1, ['location' => ['lat' => 51.5080, 'lng' => -0.1280]]); // ~80m away
        $docFar = new Document('2', 1, ['location' => ['lat' => 48.8566, 'lng' => 2.3522]]);   // Paris ~340km away

        $geoFilter = Filter::geoRadius('location', 51.5074, -0.1278, 1000.0); // 1km radius

        $this->assertTrue($this->compiler->matches($geoFilter, $docNear));
        $this->assertFalse($this->compiler->matches($geoFilter, $docFar));
    }
}
