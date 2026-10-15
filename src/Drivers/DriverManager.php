<?php

declare(strict_types=1);

namespace Relevance\Drivers;

use Closure;
use InvalidArgumentException;
use Relevance\Contracts\Driver;
use Relevance\Drivers\Memory\MemoryDriver;

class DriverManager
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    /** @var array<string, Closure(): Driver> */
    private array $customCreators = [];

    public function __construct(
        private readonly ?string $defaultDriver = 'memory',
    ) {}

    public function driver(?string $name = null): Driver
    {
        $name = $name ?? $this->defaultDriver ?? 'memory';

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->createDriver($name);
    }

    public function extend(string $name, Closure $callback): self
    {
        $this->customCreators[$name] = $callback;
        unset($this->drivers[$name]);

        return $this;
    }

    public function set(string $name, Driver $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    private function createDriver(string $name): Driver
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])();
        }

        return match ($name) {
            'memory' => new MemoryDriver(),
            default => throw new InvalidArgumentException("Unsupported search driver [{$name}]."),
        };
    }
}
