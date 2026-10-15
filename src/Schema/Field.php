<?php

declare(strict_types=1);

namespace Relevance\Schema;

class Field
{
    public float $weight = 1.0;
    public bool|int $highlight = false;
    /** @var bool|list<array{0: int|float|null, 1: int|float|null}> */
    public bool|array $facet = false;
    public bool $filterable = false;
    public bool $sortable = false;
    public bool $required = false;
    public bool $multiple = false;
    public bool $sensitive = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}

    public function weight(float $weight): static
    {
        $this->weight = $weight;
        return $this;
    }

    public function highlight(bool|int $snippet = true): static
    {
        $this->highlight = $snippet;
        return $this;
    }

    /**
     * @param bool|list<array{0: int|float|null, 1: int|float|null}> $ranges
     */
    public function facet(bool|array $ranges = true): static
    {
        $this->facet = $ranges;
        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->filterable = $filterable;
        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;
        return $this;
    }

    public function required(bool $required = true): static
    {
        $this->required = $required;
        return $this;
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;
        return $this;
    }

    public function sensitive(bool $sensitive = true): static
    {
        $this->sensitive = $sensitive;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'type'       => $this->type,
            'weight'     => $this->weight,
            'highlight'  => $this->highlight,
            'facet'      => $this->facet,
            'filterable' => $this->filterable,
            'sortable'   => $this->sortable,
            'required'   => $this->required,
            'multiple'   => $this->multiple,
            'sensitive'  => $this->sensitive,
        ];
    }
}
