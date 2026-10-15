<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Relevance\Facades\Relevance;

class RelevanceBenchmarkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relevance:benchmark
                            {--index=default : The index name}
                            {--profile=default : The search profile}
                            {--count=20 : Number of benchmark iterations}
                            {--queries= : Optional comma-separated query list}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Benchmark search pipeline latency percentiles (p50, p95, p99) and throughput';

    public function handle(): int
    {
        $rawIndex = $this->option('index');
        $index = is_string($rawIndex) ? $rawIndex : 'default';

        $rawProfile = $this->option('profile');
        $profile = is_string($rawProfile) ? $rawProfile : 'default';

        $rawCount = $this->option('count');
        $count = is_numeric($rawCount) ? (int) $rawCount : 20;

        $rawQueries = $this->option('queries');
        $queriesList = is_string($rawQueries) && $rawQueries !== ''
            ? array_map('trim', explode(',', $rawQueries))
            : ['shoes', 'sneakers', 'laptop', 'phone', 'wireless'];

        $this->info("Benchmarking [{$index}] with profile [{$profile}] ({$count} iterations)...");

        $latencies = [];
        $degradedCount = 0;
        $totalHits = 0;

        $globalStart = hrtime(true);

        for ($i = 0; $i < $count; $i++) {
            $q = $queriesList[$i % count($queriesList)];
            $t0 = hrtime(true);

            $res = Relevance::index($index)->query($q)->profile($profile)->limit(20)->get();

            $ms = (hrtime(true) - $t0) / 1e6;
            $latencies[] = $ms;
            $totalHits += count($res->hits);
            if ($res->isDegraded()) {
                $degradedCount++;
            }
        }

        $totalDurationMs = (hrtime(true) - $globalStart) / 1e6;
        $qps = ($count / max(0.001, $totalDurationMs / 1000));

        sort($latencies);
        $p50 = $latencies[(int) floor(0.50 * (count($latencies) - 1))];
        $p95 = $latencies[(int) floor(0.95 * (count($latencies) - 1))];
        $p99 = $latencies[(int) floor(0.99 * (count($latencies) - 1))];

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Queries', (string) $count],
                ['Total Duration', number_format($totalDurationMs, 2) . ' ms'],
                ['Throughput', number_format($qps, 2) . ' QPS'],
                ['p50 Latency', number_format($p50, 2) . ' ms'],
                ['p95 Latency', number_format($p95, 2) . ' ms'],
                ['p99 Latency', number_format($p99, 2) . ' ms'],
                ['Degraded Responses', (string) $degradedCount],
            ]
        );

        $this->info('Benchmark completed successfully.');

        return self::SUCCESS;
    }
}
