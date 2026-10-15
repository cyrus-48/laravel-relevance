<?php

declare(strict_types=1);

namespace Relevance\Support\Privacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relevance\Events\UserPrivacyErased;

class PrivacyManager
{
    /**
     * Erase all records belonging to the given user subject (GDPR right to erasure).
     */
    public function erase(mixed $user): PrivacyErasureReport
    {
        $userRef = $this->resolveUserRef($user);
        $deletedCounts = [];
        $total = 0;

        $tables = [
            'relevance_events' => 'user_ref',
            'relevance_interactions' => 'user_ref',
            'relevance_search_logs' => 'user_ref',
            'relevance_user_factors' => 'user_ref',
        ];

        foreach ($tables as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $count = DB::table($table)->where($column, $userRef)->delete();
                $deletedCounts[$table] = $count;
                $total += $count;
            } else {
                $deletedCounts[$table] = 0;
            }
        }

        $report = new PrivacyErasureReport(
            userRef: $userRef,
            deletedCounts: $deletedCounts,
            totalDeleted: $total,
        );

        event(new UserPrivacyErased($report));

        return $report;
    }

    /**
     * Export all records belonging to the given user subject (GDPR right of access/data portability).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(mixed $user): array
    {
        $userRef = $this->resolveUserRef($user);
        $exported = [];

        $tables = [
            'events' => ['table' => 'relevance_events', 'col' => 'user_ref'],
            'interactions' => ['table' => 'relevance_interactions', 'col' => 'user_ref'],
            'search_logs' => ['table' => 'relevance_search_logs', 'col' => 'user_ref'],
            'user_factors' => ['table' => 'relevance_user_factors', 'col' => 'user_ref'],
        ];

        foreach ($tables as $key => $info) {
            $table = $info['table'];
            $col = $info['col'];
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                /** @var list<array<string, mixed>> $rows */
                $rows = DB::table($table)
                    ->where($col, $userRef)
                    ->get()
                    ->map(static fn ($r) => (array) $r)
                    ->all();
                $exported[$key] = $rows;
            } else {
                $exported[$key] = [];
            }
        }

        return $exported;
    }

    public function resolveUserRef(mixed $user): string
    {
        if ($user instanceof Model) {
            return (string) $user->getKey();
        }

        if (is_object($user) && isset($user->id)) {
            return (string) $user->id;
        }

        return (string) $user;
    }
}
