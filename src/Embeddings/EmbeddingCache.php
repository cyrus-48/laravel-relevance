<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Illuminate\Support\Facades\DB;

class EmbeddingCache
{
    /**
     * @return list<float>|null
     */
    public function get(string $fingerprint, string $input): ?array
    {
        $key = hash('sha256', $fingerprint . ':' . $input);
        $row = DB::table('relevance_embedding_cache')->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        /** @var list<float> $vector */
        $vector = array_values(array_map('floatval', (array) json_decode((string) $row->vector, true, 512, JSON_THROW_ON_ERROR)));
        return $vector;
    }

    /**
     * @param list<string> $inputs
     * @return array<string, list<float>> input => vector
     */
    public function getMany(string $fingerprint, array $inputs): array
    {
        if (empty($inputs)) {
            return [];
        }

        $keyToInput = [];
        foreach ($inputs as $input) {
            $key = hash('sha256', $fingerprint . ':' . $input);
            $keyToInput[$key] = $input;
        }

        $rows = DB::table('relevance_embedding_cache')
            ->whereIn('key', array_keys($keyToInput))
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $input = $keyToInput[$row->key] ?? null;
            if ($input !== null) {
                /** @var list<float> $vector */
                $vector = array_values(array_map('floatval', (array) json_decode((string) $row->vector, true, 512, JSON_THROW_ON_ERROR)));
                $results[$input] = $vector;
            }
        }

        return $results;
    }

    /**
     * @param list<float> $vector
     */
    public function put(string $fingerprint, string $input, array $vector, ?int $tokens = null): void
    {
        $key = hash('sha256', $fingerprint . ':' . $input);

        DB::table('relevance_embedding_cache')->upsert([
            'key'         => $key,
            'fingerprint' => $fingerprint,
            'vector'      => json_encode($vector, JSON_THROW_ON_ERROR),
            'tokens'      => $tokens,
            'created_at'  => now(),
        ], ['key'], ['vector', 'tokens']);
    }

    /**
     * @param array<string, list<float>> $vectors input => vector
     */
    public function putMany(string $fingerprint, array $vectors, ?int $tokensPerItem = null): void
    {
        if (empty($vectors)) {
            return;
        }

        $records = [];
        $now = now();

        foreach ($vectors as $input => $vector) {
            $records[] = [
                'key'         => hash('sha256', $fingerprint . ':' . $input),
                'fingerprint' => $fingerprint,
                'vector'      => json_encode($vector, JSON_THROW_ON_ERROR),
                'tokens'      => $tokensPerItem,
                'created_at'  => $now,
            ];
        }

        DB::table('relevance_embedding_cache')->upsert($records, ['key'], ['vector', 'tokens']);
    }
}
