<?php

declare(strict_types=1);

namespace Relevance\Support;

final readonly class Deadline
{
    private function __construct(
        private ?float $targetTimestampMs = null,
        private float $startedAtMs = 0.0,
    ) {}

    public static function in(float|int $milliseconds): self
    {
        $now = microtime(true) * 1000;

        return new self($now + (float) $milliseconds, $now);
    }

    public static function fromSeconds(float|int $seconds): self
    {
        return self::in($seconds * 1000);
    }

    public static function never(): self
    {
        return new self(null, microtime(true) * 1000);
    }

    public function exceeded(): bool
    {
        if ($this->targetTimestampMs === null) {
            return false;
        }

        return (microtime(true) * 1000) >= $this->targetTimestampMs;
    }

    public function remainingMs(): float
    {
        if ($this->targetTimestampMs === null) {
            return INF;
        }

        $remaining = $this->targetTimestampMs - (microtime(true) * 1000);

        return max(0.0, $remaining);
    }

    public function elapsedMs(): float
    {
        return max(0.0, (microtime(true) * 1000) - $this->startedAtMs);
    }
}
