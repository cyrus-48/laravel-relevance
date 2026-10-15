<?php

declare(strict_types=1);

namespace Relevance\Search\Boosts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Relevance\Data\Hit;
use Relevance\Support\Decay;

class BoostEngine
{
    /**
     * @param list<Hit> $hits
     * @param array<int, array<string, mixed>> $boostConfigs
     * @return list<Hit>
     */
    public function apply(array $hits, array $boostConfigs, float $boostCap = 0.35): array
    {
        if (empty($hits) || empty($boostConfigs)) {
            return $hits;
        }

        $now = Carbon::now();
        $boosted = [];

        foreach ($hits as $hit) {
            $sum = 0.0;
            $details = [];

            foreach ($boostConfigs as $cfg) {
                $field = (string) ($cfg['field'] ?? '');
                $fn = (string) ($cfg['fn'] ?? 'bool');
                $weight = (float) ($cfg['weight'] ?? 0.0);
                $rawVal = $hit->fields[$field] ?? null;

                $signal = $this->calculateSignal($rawVal, $fn, $cfg, $now);
                $contribution = $weight * $signal;
                $sum += $contribution;

                $details[] = [
                    'field' => $field,
                    'fn' => $fn,
                    'raw' => $rawVal,
                    'signal' => $signal,
                    'weight' => $weight,
                    'contribution' => $contribution,
                ];
            }

            // Clamped multiplier: clamp(1 + sum, 1 - cap, 1 + cap)
            $multiplier = max(1.0 - $boostCap, min(1.0 + $boostCap, 1.0 + $sum));
            $newScore = $hit->score * $multiplier;

            $boosted[] = $hit->with($newScore, 'boost', [
                'multiplier' => $multiplier,
                'signals' => $details,
            ]);
        }

        // Deterministic re-ranking
        usort($boosted, static function (Hit $a, Hit $b): int {
            return ($b->score <=> $a->score) ?: strcmp($a->id, $b->id);
        });

        return $boosted;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function calculateSignal(mixed $val, string $fn, array $cfg, CarbonInterface $now): float
    {
        if ($val === null) {
            return 0.0;
        }

        return match ($fn) {
            'bool' => (bool) $val ? 1.0 : 0.0,
            'log' => $this->calculateLogSignal($val, $cfg),
            'decay' => $this->calculateDecaySignal($val, $cfg, $now),
            'linear' => $this->calculateLinearSignal($val, $cfg),
            default => 0.0,
        };
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function calculateLogSignal(mixed $val, array $cfg): float
    {
        $num = is_numeric($val) ? (float) $val : 0.0;
        $max = (float) ($cfg['max'] ?? 1000.0);

        if ($max <= 0.0) {
            $max = 1000.0;
        }

        $signal = log1p(max(0.0, $num)) / log1p($max);
        return max(0.0, min(1.0, $signal));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function calculateDecaySignal(mixed $val, array $cfg, CarbonInterface $now): float
    {
        $halfLifeDays = (float) ($cfg['half_life_days'] ?? 30.0);
        if ($halfLifeDays <= 0.0) {
            $halfLifeDays = 30.0;
        }

        $date = null;
        if ($val instanceof CarbonInterface) {
            $date = $val;
        } elseif ($val instanceof DateTimeInterface) {
            $date = Carbon::instance($val);
        } elseif (is_string($val) || is_int($val)) {
            try {
                $date = is_int($val) ? Carbon::createFromTimestamp($val) : Carbon::parse($val);
            } catch (\Throwable) {
                return 0.0;
            }
        }

        if ($date === null) {
            return 0.0;
        }

        return Decay::factor($date, $now, $halfLifeDays);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function calculateLinearSignal(mixed $val, array $cfg): float
    {
        $num = is_numeric($val) ? (float) $val : 0.0;
        $min = (float) ($cfg['min'] ?? 0.0);
        $max = (float) ($cfg['max'] ?? 1.0);

        if ($max <= $min) {
            return 0.0;
        }

        $signal = ($num - $min) / ($max - $min);
        return max(0.0, min(1.0, $signal));
    }
}
