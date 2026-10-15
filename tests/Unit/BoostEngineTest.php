<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Relevance\Data\Hit;
use Relevance\Search\Boosts\BoostEngine;
use Relevance\Search\Profiles\ProfileDefinition;
use Relevance\Search\Profiles\ProfileRepository;

class BoostEngineTest extends TestCase
{
    public function test_profile_resolution_and_inheritance(): void
    {
        $configs = [
            'base' => [
                'index' => 'products',
                'mode' => 'hybrid',
                'semantic_ratio' => 0.5,
                'boost_cap' => 0.35,
                'boosts' => [
                    ['field' => 'in_stock', 'fn' => 'bool', 'weight' => 0.10],
                ],
                'version' => 1,
            ],
            'custom' => [
                'extends' => 'base',
                'semantic_ratio' => 0.2,
                'boost_cap' => 0.50,
                'version' => 2,
            ],
        ];

        $repo = new ProfileRepository($configs);
        $profile = $repo->resolve('custom');

        $this->assertSame('custom', $profile->name);
        $this->assertSame('products', $profile->index);
        $this->assertEquals(0.2, $profile->semanticRatio);
        $this->assertEquals(0.50, $profile->boostCap);
        $this->assertSame(2, $profile->version);
        $this->assertCount(1, $profile->boosts);
        $this->assertSame('in_stock', $profile->boosts[0]['field']);
    }

    public function test_boost_engine_bool_signal(): void
    {
        $engine = new BoostEngine();

        $hits = [
            new Hit(id: '1', score: 1.0, fields: ['in_stock' => false]),
            new Hit(id: '2', score: 1.0, fields: ['in_stock' => true]),
        ];

        $boostConfigs = [
            ['field' => 'in_stock', 'fn' => 'bool', 'weight' => 0.20],
        ];

        $boosted = $engine->apply($hits, $boostConfigs, boostCap: 0.35);

        // Hit 2 should be in_stock (multiplier 1.20), hit 1 out of stock (multiplier 1.0)
        $this->assertSame('2', $boosted[0]->id);
        $this->assertEqualsWithDelta(1.20, $boosted[0]->score, 0.001);
        $this->assertSame('1', $boosted[1]->id);
        $this->assertEqualsWithDelta(1.0, $boosted[1]->score, 0.001);
    }

    public function test_boost_engine_log_and_decay_signals_bounded_by_cap(): void
    {
        $engine = new BoostEngine();

        Carbon::setTestNow('2026-10-15 12:00:00');

        $hits = [
            new Hit(id: '1', score: 1.0, fields: [
                'sales' => 1000,
                'published_at' => '2026-10-15 12:00:00', // fresh
            ]),
        ];

        $boostConfigs = [
            ['field' => 'sales', 'fn' => 'log', 'max' => 1000, 'weight' => 0.50],
            ['field' => 'published_at', 'fn' => 'decay', 'half_life_days' => 30, 'weight' => 0.50],
        ];

        // Sum = 0.50 + 0.50 = 1.0. But boostCap = 0.35, so clamped to 1.35
        $boosted = $engine->apply($hits, $boostConfigs, boostCap: 0.35);

        $this->assertEqualsWithDelta(1.35, $boosted[0]->score, 0.001);
        $this->assertArrayHasKey('boost', $boosted[0]->breakdown->stages);

        Carbon::setTestNow();
    }
}
