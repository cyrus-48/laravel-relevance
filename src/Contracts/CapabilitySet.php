<?php

declare(strict_types=1);

namespace Relevance\Contracts;

final readonly class CapabilitySet
{
    /** @var array<string, Capability> */
    private array $capabilities;

    /**
     * @param list<Capability> $capabilities
     */
    public function __construct(array $capabilities = [])
    {
        $map = [];
        foreach ($capabilities as $cap) {
            $map[$cap->value] = $cap;
        }
        $this->capabilities = $map;
    }

    public static function of(Capability ...$capabilities): self
    {
        return new self(array_values($capabilities));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function has(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function missing(Capability $capability): bool
    {
        return !$this->has($capability);
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_keys($this->capabilities);
    }
}
