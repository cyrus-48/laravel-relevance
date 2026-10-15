<?php

declare(strict_types=1);

namespace Relevance\Tracking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Facades\Relevance;

class Tracker
{
    private ?string $userRef = null;
    private ?string $anonRef = null;
    private ?string $tenantId = null;
    private ?string $sessionId = null;
    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * @var array<string, float>
     */
    private static array $defaultWeights = [
        'click'       => 1.0,
        'view'        => 1.5,
        'favorite'    => 3.0,
        'add_to_cart' => 4.0,
        'purchase'    => 6.0,
        'dismiss'     => -3.0,
    ];

    public function forUser(mixed $user): self
    {
        $this->userRef = $this->resolveUserRef($user);
        return $this;
    }

    public function anon(string $anonId): self
    {
        $this->anonRef = $anonId;
        return $this;
    }

    public function tenant(?string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function session(?string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function context(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function click(
        mixed $item,
        ?string $searchId = null,
        ?int $position = null,
        ?float $value = null,
        ?string $placement = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'click',
            item: $item,
            value: $value,
            searchId: $searchId,
            position: $position,
            placement: $placement,
            index: $index,
        );
    }

    public function view(
        mixed $item,
        ?string $searchId = null,
        ?int $position = null,
        ?float $value = null,
        ?string $placement = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'view',
            item: $item,
            value: $value,
            searchId: $searchId,
            position: $position,
            placement: $placement,
            index: $index,
        );
    }

    public function purchase(
        mixed $item,
        ?float $value = null,
        ?string $searchId = null,
        ?string $placement = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'purchase',
            item: $item,
            value: $value,
            searchId: $searchId,
            placement: $placement,
            index: $index,
        );
    }

    public function favorite(
        mixed $item,
        ?string $searchId = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'favorite',
            item: $item,
            searchId: $searchId,
            index: $index,
        );
    }

    public function addToCart(
        mixed $item,
        ?float $value = null,
        ?string $searchId = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'add_to_cart',
            item: $item,
            value: $value,
            searchId: $searchId,
            index: $index,
        );
    }

    public function dismiss(
        mixed $item,
        ?string $searchId = null,
        ?string $index = null,
    ): void {
        $this->track(
            type: 'dismiss',
            item: $item,
            searchId: $searchId,
            index: $index,
        );
    }

    public function track(
        string $type,
        mixed $item,
        ?float $weight = null,
        ?float $value = null,
        ?string $searchId = null,
        ?int $position = null,
        ?string $placement = null,
        ?string $variant = null,
        ?string $index = null,
    ): void {
        $itemId = $this->resolveItemId($item);
        $resolvedIndex = $index ?? $this->resolveIndexName($item);
        $resolvedWeight = $weight ?? (self::$defaultWeights[$type] ?? 1.0);

        // Record on fake if faked
        if (Relevance::isFaked()) {
            Relevance::fake()->recordTracked($type, $item, [
                'user_ref' => $this->userRef,
                'anon_ref' => $this->anonRef,
                'item_id' => $itemId,
                'index' => $resolvedIndex,
                'weight' => $resolvedWeight,
                'value' => $value,
                'search_id' => $searchId,
                'position' => $position,
                'placement' => $placement,
                'variant' => $variant,
                'context' => $this->context,
            ]);
            return;
        }

        try {
            if (Schema::hasTable('relevance_interactions')) {
                DB::table('relevance_interactions')->insert([
                    'index' => $resolvedIndex,
                    'tenant_id' => $this->tenantId,
                    'user_ref' => $this->userRef,
                    'anon_ref' => $this->anonRef,
                    'item_id' => $itemId,
                    'type' => $type,
                    'weight' => $resolvedWeight,
                    'value' => $value,
                    'session_id' => $this->sessionId,
                    'search_id' => $searchId,
                    'position' => $position,
                    'placement' => $placement,
                    'variant' => $variant,
                    'context' => !empty($this->context) ? json_encode($this->context) : null,
                    'occurred_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Fail-soft: tracking never throws in user paths
        }
    }

    public function alias(string $anonId, mixed $user): void
    {
        $userRef = $this->resolveUserRef($user);
        if ($userRef === null) {
            return;
        }

        try {
            if (Schema::hasTable('relevance_interactions')) {
                DB::table('relevance_interactions')
                    ->where('anon_ref', $anonId)
                    ->whereNull('user_ref')
                    ->update(['user_ref' => $userRef]);
            }
        } catch (\Throwable) {
            // Fail-soft
        }
    }

    private function resolveUserRef(mixed $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user instanceof Model) {
            return (string) $user->getKey();
        }

        if (is_object($user) && isset($user->id)) {
            return (string) $user->id;
        }

        return (string) $user;
    }

    private function resolveItemId(mixed $item): string
    {
        if ($item instanceof Model) {
            return (string) $item->getKey();
        }

        if (is_object($item) && isset($item->id)) {
            return (string) $item->id;
        }

        if (is_array($item) && isset($item['id'])) {
            return (string) $item['id'];
        }

        return (string) $item;
    }

    private function resolveIndexName(mixed $item): string
    {
        if ($item instanceof Model && method_exists($item, 'relevanceIndex')) {
            /** @var callable $callable */
            $callable = [$item, 'relevanceIndex'];
            $idxClass = $callable();
            if (is_string($idxClass) && class_exists($idxClass)) {
                $idx = new $idxClass();
                if (method_exists($idx, 'name')) {
                    return (string) $idx->name();
                }
            }
        }

        return 'default';
    }
}
