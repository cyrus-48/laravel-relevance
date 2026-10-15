<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

use Carbon\Carbon;

final readonly class Rule
{
    /**
     * @param array<int, array<string, mixed>> $actions
     */
    public function __construct(
        public string $name,
        public string $index,
        public int $priority,
        public RuleMatcher $matcher,
        public array $actions,
        public bool $enabled = true,
        public ?string $startAt = null,
        public ?string $endAt = null,
    ) {}

    public function isActive(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $now = Carbon::now();

        if ($this->startAt !== null && $now->lt(Carbon::parse($this->startAt))) {
            return false;
        }

        if ($this->endAt !== null && $now->gt(Carbon::parse($this->endAt))) {
            return false;
        }

        return true;
    }

    public function matches(string $query): bool
    {
        return $this->isActive() && $this->matcher->matches($query);
    }
}
