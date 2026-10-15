<?php

declare(strict_types=1);

namespace Relevance\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Relevance\Facades\Relevance;
use Relevance\RelevanceServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RelevanceServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Relevance' => Relevance::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('relevance.driver', 'memory');
    }
}
