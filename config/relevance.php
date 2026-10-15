<?php

declare(strict_types=1);

return [
    'driver' => env('RELEVANCE_DRIVER', 'memory'),

    'drivers' => [
        'memory' => [],
        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
            'key' => env('MEILISEARCH_KEY'),
            'search_key' => env('MEILISEARCH_SEARCH_KEY'),
            'timeout_ms' => 800,
        ],
        'typesense' => [
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'api_key' => env('TYPESENSE_KEY'),
            'timeout_ms' => 800,
        ],
        'elasticsearch' => [
            'hosts' => [env('ES_HOST', 'http://localhost:9200')],
            'api_key' => env('ES_API_KEY'),
            'timeout_ms' => 1000,
        ],
        'pgsql' => [
            'connection' => env('RELEVANCE_DB_CONNECTION', 'pgsql'),
            'fts_config' => 'english',
            'hnsw' => [
                'm' => 16,
                'ef_construction' => 128,
                'ef_search' => 64,
            ],
        ],
        'qdrant' => [
            'url' => env('QDRANT_URL', 'http://localhost:6333'),
            'api_key' => env('QDRANT_KEY'),
            'timeout_ms' => 800,
        ],
    ],

    // Optionally split roles across engines
    'roles' => [
        'text'   => env('RELEVANCE_TEXT_DRIVER', null),     // null -> default driver
        'vector' => env('RELEVANCE_VECTOR_DRIVER', null),
    ],

    'indexes' => [],

    'embeddings' => [
        'default' => 'fake',
        'providers' => [
            'fake' => [
                'driver' => 'fake',
                'dimensions' => 8,
            ],
            'openai' => [
                'driver' => 'openai',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'batch' => 64,
                'allow_pii' => false,
            ],
            'local' => [
                'driver' => 'http',
                'url' => env('EMBED_URL', 'http://localhost:8000/embed'),
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
            'on_exhausted' => 'defer', // defer | text_only
        ],
    ],

    'search' => [
        'default_profile' => 'default',
        'window' => 200,
        'budget_ms' => 250,
        'hydrate' => 'eloquent', // eloquent | payload
        'fallback_driver' => null,
        'understanding' => [
            'normalize' => true,
            'spell' => true,
            'synonyms' => true,
            'llm_rewrite' => false,
        ],
        'cache' => [
            'store' => 'redis',
            'ttl' => 120,
            'swr' => 600,
        ],
    ],

    'recommendations' => [
        'placements' => [],
        'interaction_weights' => [
            'click' => 1.0,
            'view' => 1.5,
            'favorite' => 3.0,
            'add_to_cart' => 4.0,
            'purchase' => 6.0,
            'dismiss' => -3.0,
        ],
        'half_life_days' => 30,
        'history_cap' => 200,
        'neighbors' => [
            'per_item' => 100,
            'min_support' => 3,
            'shrinkage' => 20,
        ],
        'trainer' => env('RELEVANCE_TRAINER', 'sql'), // sql | php | external
    ],

    'tracking' => [
        'enabled' => true,
        'buffer' => 'redis',
        'flush_seconds' => 5,
        'impression_sample' => 0.1,
        'bot_filter' => true,
        'hash_user_ids' => true,
    ],

    'tenancy' => [
        'required' => false,
        'resolver' => null,
        'field' => 'tenant_id',
    ],

    'experiments' => [
        'enabled' => true,
        'unit' => 'user',
        'salt' => env('RELEVANCE_SALT', env('APP_KEY')),
    ],

    'queue' => [
        'connection' => 'sync',
        'queue' => 'relevance-indexing',
        'batch_size' => 500,
        'drain_every_seconds' => 2,
        'max_attempts' => 8,
    ],

    'resilience' => [
        'breaker' => [
            'failures' => 5,
            'window_s' => 30,
            'cooldown_s' => 20,
        ],
    ],

    'retention' => [
        'interactions_days' => 395,
        'search_logs_days' => 90,
        'impressions_days' => 30,
    ],

    'http' => [
        'enabled' => false,
        'prefix' => 'relevance',
        'middleware' => ['api'],
    ],

    'observability' => [
        'log_sample' => 0.05,
        'explain_sample' => 0.01,
        'otel' => false,
    ],
];
