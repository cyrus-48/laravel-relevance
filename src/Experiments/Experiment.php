<?php

declare(strict_types=1);

namespace Relevance\Experiments;

final readonly class Experiment
{
    /**
     * @param array<string, string> $variants
     * @param array<int|string, int> $split
     */
    public function __construct(
        public string $name,
        public string $index = '*',
        public string $type = 'search_profile',
        public array $variants = [],
        public array $split = [50, 50],
        public string $status = 'running',
        public string $salt = '',
    ) {}

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * @return array{variant: string, target: string}
     */
    public function assign(string $unitId): array
    {
        $bucketer = new Bucketer();
        return $bucketer->assign($unitId, $this->variants, $this->split, $this->salt ?: $this->name);
    }
}
