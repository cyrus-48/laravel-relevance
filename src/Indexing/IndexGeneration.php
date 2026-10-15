<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $index
 * @property int $generation
 * @property string $physical_name
 * @property string|null $embedding_fingerprint
 * @property string|null $schema_hash
 * @property string $state
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<IndexGeneration> forIndex(string $index)
 * @method static Builder<IndexGeneration> active()
 * @method static Builder<IndexGeneration> building()
 */
class IndexGeneration extends Model
{
    protected $table = 'relevance_index_generations';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'generation'   => 'integer',
        'meta'         => 'array',
        'activated_at' => 'datetime',
        'retired_at'   => 'datetime',
    ];

    /**
     * @param Builder<IndexGeneration> $query
     * @return Builder<IndexGeneration>
     */
    public function scopeForIndex(Builder $query, string $index): Builder
    {
        return $query->where('index', $index);
    }

    /**
     * @param Builder<IndexGeneration> $query
     * @return Builder<IndexGeneration>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 'active');
    }

    /**
     * @param Builder<IndexGeneration> $query
     * @return Builder<IndexGeneration>
     */
    public function scopeBuilding(Builder $query): Builder
    {
        return $query->where('state', 'building');
    }
}
