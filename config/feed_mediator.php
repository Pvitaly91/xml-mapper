<?php

return [
    'storage_disk' => env('FEED_MEDIATOR_STORAGE_DISK', 'local'),
    'imports_directory' => env('FEED_MEDIATOR_IMPORTS_DIRECTORY', 'imports/prom'),
    'builds_directory' => env('FEED_MEDIATOR_BUILDS_DIRECTORY', 'feeds/builds'),
    'published_directory' => env('FEED_MEDIATOR_PUBLISHED_DIRECTORY', 'feeds/published'),
    'feedback_directory' => env('FEED_MEDIATOR_FEEDBACK_DIRECTORY', 'imports/feedback'),
    'runbooks_directory' => env('FEED_MEDIATOR_RUNBOOKS_DIRECTORY', 'feeds/runbooks'),
    'health_cache_key' => env('FEED_MEDIATOR_HEALTH_CACHE_KEY', 'feed-mediator:health'),
    'kasta_dictionary_stub_path' => env('FEED_MEDIATOR_KASTA_DICTIONARY_STUB_PATH', base_path('database/data/kasta')),
    'kasta_dictionary_sample_path' => env('FEED_MEDIATOR_KASTA_DICTIONARY_SAMPLE_PATH', base_path('database/samples/kasta-dictionaries')),
    'kasta_dictionary_storage_directory' => env('FEED_MEDIATOR_KASTA_DICTIONARY_STORAGE_DIRECTORY', 'imports/dictionaries'),
    'queues' => [
        'imports' => env('FEED_MEDIATOR_QUEUE_IMPORTS', 'imports'),
        'normalization' => env('FEED_MEDIATOR_QUEUE_NORMALIZATION', 'normalization'),
        'feeds' => env('FEED_MEDIATOR_QUEUE_FEEDS', 'feeds'),
        'dictionaries' => env('FEED_MEDIATOR_QUEUE_DICTIONARIES', 'dictionaries'),
    ],
    'locks' => [
        'prefix' => env('FEED_MEDIATOR_LOCK_PREFIX', 'feed-mediator'),
        'dispatch_ttl_seconds' => (int) env('FEED_MEDIATOR_DISPATCH_LOCK_TTL_SECONDS', 1800),
        'source_sync_ttl_seconds' => (int) env('FEED_MEDIATOR_SOURCE_SYNC_LOCK_TTL_SECONDS', 1800),
        'feed_build_ttl_seconds' => (int) env('FEED_MEDIATOR_FEED_BUILD_LOCK_TTL_SECONDS', 1800),
        'feed_publish_ttl_seconds' => (int) env('FEED_MEDIATOR_FEED_PUBLISH_LOCK_TTL_SECONDS', 900),
        'dictionary_import_ttl_seconds' => (int) env('FEED_MEDIATOR_DICTIONARY_IMPORT_LOCK_TTL_SECONDS', 1800),
    ],
    'ops' => [
        'scheduler_heartbeat_key' => env('FEED_MEDIATOR_SCHEDULER_HEARTBEAT_KEY', 'feed-mediator:ops:scheduler-heartbeat'),
        'worker_heartbeat_key' => env('FEED_MEDIATOR_WORKER_HEARTBEAT_KEY', 'feed-mediator:ops:worker-heartbeat'),
        'heartbeat_stale_after_seconds' => (int) env('FEED_MEDIATOR_HEARTBEAT_STALE_AFTER_SECONDS', 180),
        'failed_jobs_degraded_threshold' => (int) env('FEED_MEDIATOR_FAILED_JOBS_DEGRADED_THRESHOLD', 1),
    ],
    'smoke_checks' => [
        'timeout_seconds' => (int) env('FEED_MEDIATOR_SMOKE_TIMEOUT_SECONDS', 15),
        'latency_warning_ms' => (int) env('FEED_MEDIATOR_SMOKE_LAT_WARN_MS', 3000),
    ],
    'first_pull_verification' => [
        'timeout_seconds' => (int) env('FEED_MEDIATOR_FIRST_PULL_TIMEOUT_SECONDS', 20),
        'warning_reverify_after_minutes' => (int) env('FEED_MEDIATOR_FIRST_PULL_REVERIFY_MINUTES', 30),
    ],
    'normalization' => [
        'article_keys' => ['article', 'vendorcode', 'vendor_code', 'артикул', 'sku'],
        'size_keys' => ['size', 'розмір', 'размер'],
        'color_keys' => ['color', 'колір', 'цвет'],
    ],
    'prom_api' => [
        'default_base_url' => env('PROM_API_BASE_URL', 'https://my.prom.ua'),
        'default_version' => env('PROM_API_VERSION', 'v1'),
        'timeout_seconds' => (int) env('PROM_API_TIMEOUT_SECONDS', 30),
        'connect_timeout_seconds' => (int) env('PROM_API_CONNECT_TIMEOUT_SECONDS', 10),
        'retry_times' => (int) env('PROM_API_RETRY_TIMES', 3),
        'retry_backoff_ms' => (int) env('PROM_API_RETRY_BACKOFF_MS', 250),
        'page_limit' => (int) env('PROM_API_PAGE_LIMIT', 100),
        'max_pages' => (int) env('PROM_API_MAX_PAGES', 500),
        'locale' => env('PROM_API_LOCALE', 'uk'),
    ],
];
