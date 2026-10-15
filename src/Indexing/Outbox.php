<?php

declare(strict_types=1);

namespace Relevance\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $index
 * @property string $doc_id
 * @property string $op
 * @property int $version
 * @property string|null $tenant_id
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property string $status
 * @property \Illuminate\Support\Carbon $available_at
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<Outbox> pending()
 */
class Outbox extends Model
{
    protected $table = 'relevance_outbox';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'version'      => 'integer',
        'attempts'     => 'integer',
        'payload'      => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * @param Builder<Outbox> $query
     * @return Builder<Outbox>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('available_at', '<=', now());
    }

    public function markProcessing(): self
    {
        $this->update([
            'status'   => 'processing',
            'attempts' => $this->attempts + 1,
        ]);

        return $this;
    }

    public function markDone(): self
    {
        $this->update([
            'status'       => 'done',
            'processed_at' => now(),
            'last_error'   => null,
        ]);

        return $this;
    }

    public function markFailed(string $error, int $maxAttempts = 5): self
    {
        $dead = $this->attempts >= $maxAttempts;

        $this->update([
            'status'       => $dead ? 'dead' : 'pending',
            'last_error'   => $error,
            'available_at' => now()->addSeconds((int) (2 ** $this->attempts)),
        ]);

        return $this;
    }
}
