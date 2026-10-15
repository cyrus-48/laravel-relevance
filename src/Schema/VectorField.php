<?php

declare(strict_types=1);

namespace Relevance\Schema;

class VectorField extends Field
{
    /** @var list<string> */
    public array $sources = [];
    public string $provider = 'default';
    public bool|int $chunk = false;

    public function __construct(string $name)
    {
        parent::__construct($name, 'vector');
    }

    /**
     * @param list<string> $sources
     */
    public function from(array $sources): static
    {
        $this->sources = $sources;
        return $this;
    }

    public function provider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function chunk(bool|int $chunk = true): static
    {
        $this->chunk = $chunk;
        return $this;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'sources'  => $this->sources,
            'provider' => $this->provider,
            'chunk'    => $this->chunk,
        ]);
    }
}
