<?php

declare(strict_types=1);

namespace Relevance\Support;

use Carbon\CarbonInterface;

final readonly class Decay
{
    public static function factor(CarbonInterface $at, CarbonInterface $now, float $halfLifeDays): float
    {
        if ($halfLifeDays <= 0) {
            return 0.0;
        }

        $diffDays = $now->diffInSeconds($at, true) / 86400.0;

        return 2.0 ** (-$diffDays / $halfLifeDays);
    }
}
