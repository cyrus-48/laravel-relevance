# 6. A/B Experiments & Offline Evaluation (NDCG CI Gate)

Relevance changes should never be deployed on guesswork. Laravel Relevance provides both **online A/B testing** and **offline relevance evaluation gates**.

---

## 6.1 Online A/B Experiments

Split users deterministically across ranking profiles or algorithm variants:

### Creating an Experiment

```php
use Relevance\Facades\Relevance;

Relevance::experiments()->create(
    name: 'hybrid_semantic_ratio_test',
    variants: [
        'control'   => 'ecommerce_default',   // semanticRatio: 0.3
        'treatment' => 'ecommerce_high_sem',  // semanticRatio: 0.7
    ],
    weights: [
        'control'   => 50,
        'treatment' => 50,
    ],
    salt: 'catalog_v1_seed',
);
```

### Deterministic Assignment & Exposure Logging

When a user executes a search, the experiment assignment is evaluated deterministically using CRC32 hashing:

```php
$response = Relevance::index('products')
    ->for($user)
    ->search('running shoes')
    ->get();

// The variant assigned and exposure are automatically logged
echo "Assigned Variant: {$response->meta['variant']}\n";
```

Exposure events are recorded in `relevance_experiment_exposures` to enable conversion, CTR, and revenue attribution analysis.

---

## 6.2 Offline Evaluation Metrics (MetricsCalculator)

The offline evaluation engine calculates standard information retrieval metrics:

- **NDCG@k (Normalized Discounted Cumulative Gain)**:
  $$\text{DCG@k} = \sum_{i=1}^k \frac{2^{rel_i} - 1}{\log_2(i + 1)}, \quad \text{NDCG@k} = \frac{\text{DCG@k}}{\text{IDCG@k}}$$
- **MRR (Mean Reciprocal Rank)**:
  $$\text{RR} = \frac{1}{\text{rank of first relevant document}}$$
- **Recall@k**: Fraction of all relevant documents captured in top-$k$ hits.
- **MAP (Mean Average Precision)**.

```php
use Relevance\Evaluation\MetricsCalculator;

$calc = new MetricsCalculator();

$retrievedIds = ['doc_1', 'doc_3', 'doc_2'];
$groundTruthJudgments = [
    'doc_1' => 3, // Highly relevant
    'doc_2' => 2, // Relevant
    'doc_3' => 0, // Irrelevant
];

$ndcg = $calc->ndcg($retrievedIds, $groundTruthJudgments, k: 10);
$mrr  = $calc->reciprocalRank($retrievedIds, $groundTruthJudgments);
```

---

## 6.3 The CI Evaluation Gate (`relevance:eval`)

Prevent ranking regressions from being merged into production.

1. Create a suite of **Golden Queries** with ground truth judgments in `relevance_judgments`:

```php
use Illuminate\Support\Facades\DB;

DB::table('relevance_judgments')->insert([
    ['query' => 'wireless headphones', 'doc_id' => 'prod_sony_wh1000xm5', 'grade' => 3],
    ['query' => 'wireless headphones', 'doc_id' => 'prod_bose_qc45', 'grade' => 3],
    ['query' => 'wireless headphones', 'doc_id' => 'prod_cheap_wired_earbuds', 'grade' => 0],
]);
```

2. Add the evaluation command to your GitHub Actions / GitLab CI pipeline:

```bash
php artisan relevance:eval --suite=golden_ecommerce --gate-ndcg=0.60
```

If the calculated mean NDCG@10 across the golden queries falls below `0.60`, the command exits with code `1`, failing the CI build and stopping the deployment.

---

## 6.4 Benchmarking Engine Latency

Measure real-world query latency across search modes:

```bash
php artisan relevance:benchmark --index=products --iterations=50
```

Outputs p50, p95, and p99 timings for lexical, vector, and hybrid retrieval.
