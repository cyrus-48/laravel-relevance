# 1. Installation & Configuration Guide

Laravel Relevance is designed to be installed cleanly into any Laravel 11, 12, or 13 application with zero external infrastructure hard-dependencies.

---

## 1.1 Requirements

- **PHP**: 8.2 or higher (8.3+ recommended)
- **Laravel Framework**: 11.0, 12.0, or 13.0
- **Database**: PostgreSQL (with optional `pgvector`), MySQL 8+, or SQLite (for local development/testing)
- **Queue Worker**: Any supported Laravel queue connection (Redis recommended)
- **Optional Search Engines**: Meilisearch, Typesense, Elasticsearch, OpenSearch, Qdrant

---

## 1.2 Installation Steps

### Step 1: Install Composer Package

```bash
composer require acme/laravel-relevance
```

### Step 2: Publish Config and Migrations

Run the installation command to publish `config/relevance.php` and database migrations:

```bash
php artisan relevance:install
```

### Step 3: Run Database Migrations

Laravel Relevance uses transactional outbox tables, generation tables, and analytics tables:

```bash
php artisan migrate
```

This creates the following tables:
- `relevance_index_generations`: Physical index aliases and generation tracking.
- `relevance_outbox`: Transactional write outbox.
- `relevance_embedding_cache`: Vector embedding memoization cache.
- `relevance_profiles`: Dynamic search ranking profiles.
- `relevance_rules`: Pre/post merchandising query rules.
- `relevance_synonyms`: One-way and two-way synonym dictionaries.
- `relevance_search_logs`: Sampled search query and latency logs.
- `relevance_interactions`: User behavioral interactions (click, view, purchase, dismiss).
- `relevance_item_neighbors`: Precomputed item similarity scores for collaborative filtering.
- `relevance_item_stats`: Real-time item popularity, clicks, and trending scores.
- `relevance_experiments`: A/B test definitions.
- `relevance_experiment_exposures`: Search and recommendation exposure logs.
- `relevance_judgments`: Human/LLM offline golden query judgments.
- `relevance_eval_runs`: CI evaluation metric runs (NDCG@10, MRR, Recall).
- `relevance_model_artifacts`: Versioned ML model artifacts.

### Step 4: Health Check (Doctor Command)

Run `relevance:doctor` to verify connectivity, database tables, and driver capabilities:

```bash
php artisan relevance:doctor
```

---

## 1.3 Configuration Reference

The published configuration file resides at `config/relevance.php`. Below are the primary options:

```php
return [
    // Default search driver: 'memory', 'meilisearch', 'pgsql', 'typesense', etc.
    'default_index_driver' => env('RELEVANCE_DRIVER', 'memory'),

    'drivers' => [
        'memory' => [],
        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
            'key' => env('MEILISEARCH_KEY'),
            'search_key' => env('MEILISEARCH_SEARCH_KEY'),
            'timeout_ms' => 800,
        ],
        'pgsql' => [
            'connection' => 'pgsql',
            'fts_config' => 'english',
            'hnsw' => ['m' => 16, 'ef_construction' => 128, 'ef_search' => 64],
        ],
    ],

    // Vector Embedding Providers
    'embeddings' => [
        'default' => 'openai',
        'providers' => [
            'openai' => [
                'driver' => 'openai',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'batch' => 64,
                'allow_pii' => false,
            ],
            'http' => [
                'driver' => 'http',
                'url' => env('EMBEDDING_URL'),
                'model' => 'bge-m3',
                'dimensions' => 1024,
            ],
        ],
        'cache' => [
            'store' => 'database',
            'query_ttl' => 86400,
        ],
        'budget' => [
            'daily_tokens' => 20_000_000,
            'on_exhausted' => 'defer', // 'defer' | 'text_only'
        ],
    ],

    // Search Defaults
    'search' => [
        'default_profile' => 'default',
        'window' => 200,
        'budget_ms' => 250,
        'hydrate' => 'eloquent', // 'eloquent' | 'payload'
        'rerankers' => [
            'cohere' => [
                'driver' => 'http',
                'url' => env('RERANK_URL', 'https://api.cohere.com/v1/rerank'),
                'key' => env('COHERE_API_KEY'),
                'model' => 'rerank-v3.5',
                'timeout_ms' => 200,
            ],
        ],
    ],

    // Recommendations Defaults
    'recommendations' => [
        'interaction_weights' => [
            'click'       => 1.0,
            'view'        => 0.5,
            'favorite'    => 3.0,
            'add_to_cart' => 4.0,
            'purchase'    => 6.0,
            'dismiss'     => -3.0,
        ],
        'half_life_days' => 30,
        'history_cap' => 200,
        'neighbors' => [
            'per_item'    => 100,
            'min_support' => 3,
            'shrinkage'   => 20,
        ],
    ],

    // Multi-Tenancy (Fail-Closed)
    'tenancy' => [
        'required' => env('RELEVANCE_TENANCY_REQUIRED', false),
        'field'    => 'tenant_id',
    ],

    // Analytics & Event Retention
    'retention' => [
        'interactions_days' => 395, // 13 months
        'search_logs_days'  => 90,
        'impressions_days'  => 30,
    ],
];
```
