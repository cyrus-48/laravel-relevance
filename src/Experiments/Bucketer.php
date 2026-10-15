<?php

declare(strict_types=1);

namespace Relevance\Experiments;

final class Bucketer
{
    /**
     * Compute a deterministic bucket in [0, 9999] for a given unit ID and salt.
     */
    public function bucket(string $unitId, string $salt = ''): int
    {
        return (crc32($salt . ':' . $unitId) & 0x7FFFFFFF) % 10000;
    }

    /**
     * Resolve a variant given variants array and split definitions.
     *
     * @param array<string, string> $variants name => target
     * @param array<int|string, int> $split [50, 50] or ['control' => 5000, 'treatment' => 5000]
     * @return array{variant: string, target: string}
     */
    public function assign(string $unitId, array $variants, array $split, string $salt = ''): array
    {
        $bucket = $this->bucket($unitId, $salt);
        $variantNames = array_keys($variants);

        if (count($variantNames) === 1) {
            $name = $variantNames[0];
            return ['variant' => $name, 'target' => $variants[$name]];
        }

        // Normalize split weights to 10,000 scale
        $thresholds = [];
        $runningSum = 0;
        $totalWeight = array_sum($split);

        if ($totalWeight <= 0) {
            $totalWeight = 100;
        }

        $i = 0;
        foreach ($variants as $vName => $target) {
            $weight = isset($split[$vName])
                ? (int) $split[$vName]
                : (int) ($split[$i] ?? (100 / count($variants)));

            $scaled = (int) round(($weight / $totalWeight) * 10000);
            $runningSum += $scaled;
            $thresholds[$vName] = $runningSum;
            $i++;
        }

        foreach ($thresholds as $vName => $threshold) {
            if ($bucket < $threshold) {
                return ['variant' => $vName, 'target' => $variants[$vName]];
            }
        }

        $lastName = (string) end($variantNames);
        return ['variant' => $lastName, 'target' => $variants[$lastName]];
    }
}
