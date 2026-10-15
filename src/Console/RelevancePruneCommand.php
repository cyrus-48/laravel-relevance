<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelevancePruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:prune
                            {--interactions-days= : Override retention period for interactions}
                            {--search-logs-days= : Override retention period for search logs}
                            {--impressions-days= : Override retention period for events/impressions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old interactions, search logs, and events exceeding retention limits';

    public function handle(): int
    {
        $rawInteractions = $this->option('interactions-days');
        $interactionsDays = is_numeric($rawInteractions)
            ? (int) $rawInteractions
            : (int) config('relevance.retention.interactions_days', 395);

        $rawSearchLogs = $this->option('search-logs-days');
        $searchLogsDays = is_numeric($rawSearchLogs)
            ? (int) $rawSearchLogs
            : (int) config('relevance.retention.search_logs_days', 90);

        $rawImpressions = $this->option('impressions-days');
        $impressionsDays = is_numeric($rawImpressions)
            ? (int) $rawImpressions
            : (int) config('relevance.retention.impressions_days', 30);

        $this->info("Pruning records older than retention policies...");

        $deletedInteractions = 0;
        if (Schema::hasTable('relevance_interactions')) {
            $cutoff = Carbon::now()->subDays($interactionsDays);
            $deletedInteractions = DB::table('relevance_interactions')
                ->where('occurred_at', '<', $cutoff)
                ->delete();
        }

        $deletedSearchLogs = 0;
        if (Schema::hasTable('relevance_search_logs')) {
            $cutoff = Carbon::now()->subDays($searchLogsDays);
            $deletedSearchLogs = DB::table('relevance_search_logs')
                ->where('created_at', '<', $cutoff)
                ->delete();
        }

        $deletedEvents = 0;
        if (Schema::hasTable('relevance_events')) {
            $cutoff = Carbon::now()->subDays($impressionsDays);
            $deletedEvents = DB::table('relevance_events')
                ->where('occurred_at', '<', $cutoff)
                ->delete();
        }

        $this->table(
            ['Dataset', 'Retention Window', 'Deleted Rows'],
            [
                ['Interactions', "{$interactionsDays} days", $deletedInteractions],
                ['Search Logs', "{$searchLogsDays} days", $deletedSearchLogs],
                ['Events / Impressions', "{$impressionsDays} days", $deletedEvents],
            ]
        );

        $total = $deletedInteractions + $deletedSearchLogs + $deletedEvents;
        $this->info("Pruning completed. Total records deleted: {$total}");

        return self::SUCCESS;
    }
}
