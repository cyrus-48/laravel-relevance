<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Data\SearchRequest;
use Relevance\Facades\Relevance;

class RelevanceExplainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:explain
                            {query : The search query to explain}
                            {--index=default : The index name}
                            {--profile=default : The profile name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explain the relevance, rules, fusion, and scoring breakdown for a search query';

    public function handle(): int
    {
        $rawQuery = $this->argument('query');
        $query = is_string($rawQuery) ? $rawQuery : '';
        $rawIndex = $this->option('index');
        $index = is_string($rawIndex) ? $rawIndex : 'default';
        $rawProfile = $this->option('profile');
        $profile = is_string($rawProfile) ? $rawProfile : 'default';

        $this->info("=== Relevance Explain: [{$query}] on [{$index}] (profile: {$profile}) ===");

        $request = new SearchRequest(
            index: $index,
            query: $query,
            profile: $profile,
        );

        $explain = Relevance::explain($request, $index);

        $this->line("<comment>Original Query:</comment>    {$explain->originalQuery}");
        $this->line("<comment>Normalized Query:</comment>  {$explain->normalizedQuery}");
        $this->line("<comment>Profile:</comment>           {$explain->profileName}");

        if (!empty($explain->synonymExpansions)) {
            $this->line("<comment>Synonym Expansions:</comment>");
            foreach ($explain->synonymExpansions as $token => $syns) {
                $this->line("  - {$token} => " . implode(', ', $syns));
            }
        }

        if (!empty($explain->rulesApplied)) {
            $this->line("<comment>Rules Applied:</comment>     " . implode(', ', $explain->rulesApplied));
        }

        if ($explain->redirectUrl !== null) {
            $this->warn("Redirect Target: {$explain->redirectUrl}");
        }

        $this->newLine();
        $this->info("--- Timings (ms) ---");
        $timingRows = [];
        foreach ($explain->timings as $stage => $ms) {
            $timingRows[] = [$stage, number_format($ms, 2) . ' ms'];
        }
        $this->table(['Stage', 'Duration'], $timingRows);

        $this->newLine();
        $this->info("--- Ranked Hits (" . count($explain->finalHits) . ") ---");
        $hitRows = [];
        foreach ($explain->finalHits as $rank => $hit) {
            $breakdownSummary = [];
            foreach ($hit->breakdown->stages as $stage => $detail) {
                if ($stage === 'fusion' && is_array($detail)) {
                    $sources = [];
                    foreach ($detail as $src => $d) {
                        if (is_array($d) && isset($d['rank'])) {
                            $sources[] = "{$src}#{$d['rank']}";
                        }
                    }
                    $breakdownSummary[] = "fusion(" . implode(', ', $sources) . ")";
                } elseif ($stage === 'boost' && is_array($detail) && isset($detail['multiplier'])) {
                    $breakdownSummary[] = "boost(×" . number_format((float) $detail['multiplier'], 2) . ")";
                } elseif ($stage === 'rule_boost') {
                    $factor = is_array($detail) && isset($detail['factor']) ? (float) $detail['factor'] : 1.0;
                    $breakdownSummary[] = "rule_boost(×" . number_format($factor, 2) . ")";
                } else {
                    $breakdownSummary[] = $stage;
                }
            }

            $hitRows[] = [
                $rank + 1,
                $hit->id,
                number_format($hit->score, 4),
                implode(', ', $breakdownSummary),
            ];
        }

        $this->table(['Rank', 'Doc ID', 'Score', 'Breakdown'], $hitRows);

        if (!empty($explain->degraded)) {
            $this->warn("Degraded stages: " . implode(', ', $explain->degraded));
        }

        return self::SUCCESS;
    }
}
