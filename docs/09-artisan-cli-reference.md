# 9. Artisan CLI Command Reference

Laravel Relevance includes a full suite of Artisan commands for development, diagnostics, operations, and CI/CD pipelines.

---

## 9.1 Setup & Diagnostics

### `relevance:install`
Publishes the package configuration (`config/relevance.php`) and database migrations.

```bash
php artisan relevance:install
```

### `relevance:doctor`
Inspects environment health, checks database table migrations, verifies driver connectivity, and prints capability matrices.

```bash
php artisan relevance:doctor
```

### `make:relevance-index {name}`
Scaffolds a new `IndexDefinition` class in `app/Search/`.

```bash
php artisan make:relevance-index ProductIndex
```

---

## 9.2 Index Operations & Outbox

### `relevance:sync`
Drains pending records from `relevance_outbox`, generates required vector embeddings, and applies upserts/deletes to active physical index generations.

```bash
php artisan relevance:sync
```

### `relevance:index:rebuild {index}`
Starts a zero-downtime rebuild into a new physical index generation (e.g. `products_g2`).

```bash
php artisan relevance:index:rebuild products
```

### `relevance:index:swap {index} {generation}`
Atomically swaps the production alias to the target physical index generation.

```bash
php artisan relevance:index:swap products 2
```

---

## 9.3 Relevance Inspection & Evaluation

### `relevance:explain "{query}"`
Traces the execution pipeline for a query, outputting term normalization, synonyms, applied merchandising rules, and scoring breakdowns.

```bash
php artisan relevance:explain "wireless headphones" --index=products
```

### `relevance:eval`
Executes offline relevance evaluation against golden query judgments. Enforces NDCG@10 thresholds as a CI gate.

```bash
# Returns exit code 1 if mean NDCG@10 < 0.65
php artisan relevance:eval --suite=golden_ecommerce --gate-ndcg=0.65
```

### `relevance:benchmark`
Runs automated latency benchmarks across text, vector, and hybrid search modes.

```bash
php artisan relevance:benchmark --index=products --iterations=100
```

---

## 9.4 Recommendations & Machine Learning

### `relevance:rec:train`
Computes Item-kNN shrunk cosine similarity scores from historical interaction logs and updates `relevance_item_neighbors`.

```bash
php artisan relevance:rec:train --index=products --min-support=3 --shrinkage=20
```

### `relevance:artifacts:activate {manifest}`
Validates external ML model artifacts (e.g. ALS, EASE, item2vec) against an offline evaluation gate before activating and importing neighbors.

```bash
php artisan relevance:artifacts:activate storage/models/manifest.json --index=products --min-metric=0.35
```

---

## 9.5 Privacy & Maintenance

### `relevance:privacy:erase {user}`
Permanently erases all analytics, search logs, interactions, and profile factors for a specific subject under GDPR Article 17.

```bash
php artisan relevance:privacy:erase user_98765 --force
```

### `relevance:prune`
Deletes interactions, search logs, and events that exceed configured data retention thresholds.

```bash
php artisan relevance:prune --interactions-days=395 --search-logs-days=90 --impressions-days=30
```
