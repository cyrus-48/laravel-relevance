<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Support\Facades\DB;
use Relevance\Contracts\IndexAdmin;
use Relevance\Drivers\DriverManager;
use Relevance\Events\IndexGenerationActivated;
use Relevance\Schema\IndexDefinition;
use Throwable;

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

        $previous = $this->activeGeneration($index);
        $driver = $this->driverManager->driver();

        // 1. Flip the alias in the engine. This is an external side effect, so it is done
        //    before (and outside of) the DB transaction that records it.
        if ($driver instanceof IndexAdmin) {
            $driver->swapAlias($index, $target->physical_name);
        }

        // 2. Record the new state. If that fails, put the alias back so the engine and the
        //    generations table never disagree about which generation is live.
        try {
            DB::transaction(function () use ($index, $target): void {
                IndexGeneration::forIndex($index)
                    ->where('state', 'active')
                    ->update([
                        'state'      => 'retired',
                        'retired_at' => now(),
                    ]);

                $target->update([
                    'state'        => 'active',
                    'activated_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            if ($driver instanceof IndexAdmin && $previous !== null) {
                $driver->swapAlias($index, $previous->physical_name);
            }

            throw $e;
        }

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
