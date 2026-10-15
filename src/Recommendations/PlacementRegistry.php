<?php

declare(strict_types=1);

namespace Relevance\Recommendations;

use Relevance\Recommendations\Data\PlacementDefinition;

class PlacementRegistry
{
    /** @var array<string, PlacementDefinition> */
    private array $placements = [];

    /**
     * @param array<string, array<string, mixed>> $configPlacements
     */
    public function __construct(array $configPlacements = [])
    {
        $defaults = [
            'home.for_you' => [
                'generators' => ['popular' => 0.5, 'trending' => 0.5],
                'filters' => ['already_purchased', 'dismissed'],
                'fallback' => ['popular'],
            ],
            'product.similar' => [
                'generators' => ['item_neighbors' => 0.7, 'content_similar' => 0.3],
                'fallback' => ['popular'],
            ],
            'product.also_purchase' => [
                'generators' => ['item_neighbors' => 1.0],
                'fallback' => ['popular'],
            ],
            'home.trending' => [
                'generators' => ['trending' => 1.0],
                'fallback' => ['popular'],
            ],
            'session.next' => [
                'generators' => ['recently_viewed' => 0.5, 'trending' => 0.5],
                'fallback' => ['popular'],
            ],
        ];

        foreach ($defaults as $name => $cfg) {
            $this->register(PlacementDefinition::fromArray($name, $cfg));
        }

        foreach ($configPlacements as $name => $cfg) {
            $this->register(PlacementDefinition::fromArray($name, $cfg));
        }
    }

    public function register(PlacementDefinition $definition): self
    {
        $this->placements[$definition->name] = $definition;
        return $this;
    }

    public function get(string $name): PlacementDefinition
    {
        return $this->placements[$name] ?? new PlacementDefinition(name: $name);
    }

    public function has(string $name): bool
    {
        return isset($this->placements[$name]);
    }
}
