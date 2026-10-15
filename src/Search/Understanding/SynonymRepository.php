<?php

declare(strict_types=1);

namespace Relevance\Search\Understanding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SynonymRepository
{
    /** @var array<string, array<string, list<string>>> */
    private array $oneWay = []; // [index => [source => [targets]]]

    /** @var array<string, list<list<string>>> */
    private array $multiWay = []; // [index => [[words]]]

    /**
     * @param string|list<string> $synonyms
     */
    public function oneWay(string $source, string|array $synonyms, string $index = 'default', ?string $locale = null): self
    {
        $src = mb_strtolower(trim($source));
        $targets = array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) $synonyms);

        $this->oneWay[$index][$src] = array_values(array_unique(array_merge(
            $this->oneWay[$index][$src] ?? [],
            $targets
        )));

        $this->persistIfTableExists($index, 'one_way', $src, $targets, $locale);

        return $this;
    }

    /**
     * @param list<string> $synonyms
     */
    public function multiWay(array $synonyms, string $index = 'default', ?string $locale = null): self
    {
        $normalized = array_values(array_unique(array_map(
            fn ($s) => mb_strtolower(trim((string) $s)),
            $synonyms
        )));

        if (count($normalized) >= 2) {
            $this->multiWay[$index][] = $normalized;
            $this->persistIfTableExists($index, 'multi_way', null, $normalized, $locale);
        }

        return $this;
    }

    /**
     * @return array{one_way: array<string, list<string>>, multi_way: list<list<string>>}
     */
    public function getSynonymsForIndex(string $index, ?string $locale = null): array
    {
        $oneWay = $this->oneWay[$index] ?? [];
        $multiWay = $this->multiWay[$index] ?? [];

        // Also check DB if table exists
        try {
            if (Schema::hasTable('relevance_synonyms')) {
                $query = DB::table('relevance_synonyms')
                    ->where('index', $index);

                if ($locale !== null) {
                    $query->where(function ($q) use ($locale) {
                        $q->whereNull('locale')->orWhere('locale', $locale);
                    });
                }

                $rows = $query->get();
                foreach ($rows as $row) {
                    /** @var list<string> $words */
                    $words = is_string($row->synonyms) ? json_decode($row->synonyms, true) : (array) $row->synonyms;

                    if ($row->type === 'one_way' && $row->source !== null) {
                        $src = (string) $row->source;
                        $oneWay[$src] = array_values(array_unique(array_merge($oneWay[$src] ?? [], $words)));
                    } elseif ($row->type === 'multi_way') {
                        $multiWay[] = $words;
                    }
                }
            }
        } catch (\Throwable) {
            // DB not ready
        }

        return [
            'one_way' => $oneWay,
            'multi_way' => $multiWay,
        ];
    }

    /**
     * @param list<string> $synonyms
     */
    private function persistIfTableExists(string $index, string $type, ?string $source, array $synonyms, ?string $locale): void
    {
        try {
            if (Schema::hasTable('relevance_synonyms')) {
                DB::table('relevance_synonyms')->insert([
                    'index' => $index,
                    'type' => $type,
                    'source' => $source,
                    'synonyms' => json_encode($synonyms),
                    'locale' => $locale,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Ignore if DB not ready
        }
    }
}
