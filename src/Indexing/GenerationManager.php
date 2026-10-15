<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Support\Facades\DB;
use Relevance\Contracts\IndexAdmin;
use Relevance\Drivers\DriverManager;
use Relevance\Events\IndexGenerationActivated;
use Relevance\Schema\IndexDefinition;

class GenerationManager
{
    public function __construct(
        private readonly DriverManager $driverManager,
    ) {}

    public function nextGeneration(string $index): int
    {
        $max = IndexGeneration::forIndex($index)->max('generation');
        return ($max !== null ? (int) $max : 0) + 1;
    }

    public function rebuild(IndexDefinition $def): IndexGeneration
    {
        $gen = $this->nextGeneration($def->name());
        $physicalName = "{$def->name()}_g{$gen}";

        $driver = $this->driverManager->driver();
        if ($driver instanceof IndexAdmin) {
            $driver->create($def->compiledSchema(), $physicalName);
        }

        return IndexGeneration::create([
            'index'                 => $def->name(),
            'generation'            => $gen,
            'physical_name'         => $physicalName,
            'schema_hash'           => $def->schemaHash(),
            'embedding_fingerprint' => $def->compiledSchema()->embeddingFingerprint,
            'state'                 => 'building',
        ]);
    }

    public function activate(string $index, int $gen): void
    {
        $target = IndexGeneration::forIndex($index)
            ->where('generation', $gen)
            ->firstOrFail();

        DB::transaction(function () use ($index, $target) {
            $driver = $this->driverManager->driver();
            if ($driver instanceof IndexAdmin) {
                $driver->swapAlias($index, $target->physical_name);
            }

            // Retire currently active generation
            IndexGeneration::forIndex($index)
                ->where('state', 'active')
                ->update([
                    'state'      => 'retired',
                    'retired_at' => now(),
                ]);

            // Activate new generation
            $target->update([
                'state'        => 'active',
                'activated_at' => now(),
            ]);
        });

        event(new IndexGenerationActivated($index, $gen));
    }

    public function activeGeneration(string $index): ?IndexGeneration
    {
        /** @var IndexGeneration|null $active */
        $active = IndexGeneration::forIndex($index)->active()->first();
        return $active;
    }

    /**
     * Get active and building physical index names for dual-writes.
     *
     * @return list<string>
     */
    public function targetPhysicalNames(string $index): array
    {
        $gens = IndexGeneration::forIndex($index)
            ->whereIn('state', ['active', 'building'])
            ->pluck('physical_name')
            ->all();

        return !empty($gens) ? array_values(array_map('strval', $gens)) : [$index];
    }
}
