<?php

declare(strict_types=1);

namespace Relevance\Experiments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Data\SearchRequest;

class ExperimentManager
{
    /** @var array<string, Experiment> */
    private array $experiments = [];

    /**
     * @param array<string, string> $variants
     * @param array<int|string, int> $split
     */
    public function create(
        string $name,
        array $variants,
        array $split = [50, 50],
        string $index = 'default',
        string $type = 'search_profile',
    ): Experiment {
        $exp = new Experiment(
            name: $name,
            index: $index,
            type: $type,
            variants: $variants,
            split: $split,
            status: 'running',
            salt: $name,
        );

        $this->experiments[$name] = $exp;

        try {
            if (Schema::hasTable('relevance_experiments')) {
                DB::table('relevance_experiments')->updateOrInsert(
                    ['name' => $name],
                    [
                        'index' => $index,
                        'type' => $type,
                        'variants' => json_encode($variants),
                        'split' => json_encode($split),
                        'status' => 'running',
                        'salt' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        } catch (\Throwable) {
            // Fail-soft
        }

        return $exp;
    }

    /**
     * @return array{experiment: string, variant: string, profile: string}|null
     */
    public function assign(SearchRequest $request): ?array
    {
        $unitId = $request->context->userId ?? $request->context->anonId;
        if ($unitId === null) {
            return null;
        }

        $experiment = $this->findRunningExperimentForIndex($request->index);
        if ($experiment === null) {
            return null;
        }

        $assignment = $experiment->assign($unitId);

        return [
            'experiment' => $experiment->name,
            'variant' => $assignment['variant'],
            'profile' => $assignment['target'],
        ];
    }

    public function recordExposure(
        string $experiment,
        string $unitId,
        string $variant,
        ?string $searchId = null,
        ?string $placement = null,
    ): void {
        try {
            if (Schema::hasTable('relevance_experiment_exposures')) {
                DB::table('relevance_experiment_exposures')->insert([
                    'experiment_name' => $experiment,
                    'unit_id' => $unitId,
                    'variant' => $variant,
                    'search_id' => $searchId,
                    'placement' => $placement,
                    'exposed_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Fail-soft
        }
    }

    /**
     * @return array{experiment: string, exposures: array<string, int>}
     */
    public function report(string $experiment): array
    {
        $exposures = [];

        try {
            if (Schema::hasTable('relevance_experiment_exposures')) {
                $rows = DB::table('relevance_experiment_exposures')
                    ->select('variant', DB::raw('COUNT(*) as total'))
                    ->where('experiment_name', $experiment)
                    ->groupBy('variant')
                    ->get();

                foreach ($rows as $row) {
                    $exposures[(string) $row->variant] = (int) $row->total;
                }
            }
        } catch (\Throwable) {
            // Fail-soft
        }

        return [
            'experiment' => $experiment,
            'exposures' => $exposures,
        ];
    }

    private function findRunningExperimentForIndex(string $index): ?Experiment
    {
        // 1. Check in-memory
        foreach ($this->experiments as $exp) {
            if ($exp->isRunning() && ($exp->index === $index || $exp->index === '*')) {
                return $exp;
            }
        }

        // 2. Check DB
        try {
            if (Schema::hasTable('relevance_experiments')) {
                $row = DB::table('relevance_experiments')
                    ->where(function ($q) use ($index) {
                        $q->where('index', $index)->orWhere('index', '*');
                    })
                    ->where('status', 'running')
                    ->first();

                if ($row !== null) {
                    /** @var array<string, string> $variants */
                    $variants = is_string($row->variants) ? json_decode($row->variants, true) : (array) $row->variants;
                    /** @var array<int|string, int> $split */
                    $split = is_string($row->split) ? json_decode($row->split, true) : (array) $row->split;

                    $exp = new Experiment(
                        name: (string) $row->name,
                        index: (string) $row->index,
                        type: (string) $row->type,
                        variants: $variants,
                        split: $split,
                        status: (string) $row->status,
                        salt: (string) ($row->salt ?? $row->name),
                    );

                    $this->experiments[$exp->name] = $exp;
                    return $exp;
                }
            }
        } catch (\Throwable) {
            // Fail-soft
        }

        return null;
    }
}
