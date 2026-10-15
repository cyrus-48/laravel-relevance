<?php

declare(strict_types=1);

namespace Relevance\Tests\Contract;

use Relevance\Contracts\Driver;
use Relevance\Drivers\Memory\MemoryDriver;
use Relevance\Testing\SearchDriverContractTest;

class MemoryDriverContractTest extends SearchDriverContractTest
{
    protected function createDriver(): Driver
    {
        return new MemoryDriver();
    }
}
