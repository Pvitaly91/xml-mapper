<?php

return [
    'environment' => [
        'class' => env('FEED_MEDIATOR_ENV_CLASS', env('APP_ENV', 'local')),
        'label' => env('FEED_MEDIATOR_ENV_LABEL'),
        'staging_public_publish_note' => env(
            'FEED_MEDIATOR_STAGING_PUBLISH_NOTE',
            'Staging publish is isolated from the production merchant feed URL.'
        ),
    ],
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
    'preflight' => [
        'require_redis' => (bool) env('FEED_MEDIATOR_PREFLIGHT_REQUIRE_REDIS', true),
        'required_directories' => array_values(array_filter([
            trim((string) env('FEED_MEDIATOR_IMPORTS_DIRECTORY', 'imports/prom'), '/'),
            trim((string) env('FEED_MEDIATOR_BUILDS_DIRECTORY', 'feeds/builds'), '/'),
            trim((string) env('FEED_MEDIATOR_PUBLISHED_DIRECTORY', 'feeds/published'), '/'),
            trim((string) env('FEED_MEDIATOR_FEEDBACK_DIRECTORY', 'imports/feedback'), '/'),
            trim((string) env('FEED_MEDIATOR_RUNBOOKS_DIRECTORY', 'feeds/runbooks'), '/'),
            trim((string) env('FEED_MEDIATOR_KASTA_DICTIONARY_STORAGE_DIRECTORY', 'imports/dictionaries'), '/'),
            trim((string) env('FEED_MEDIATOR_BACKUPS_DB_DIRECTORY', 'ops/backups/db'), '/'),
            trim((string) env('FEED_MEDIATOR_BACKUPS_FILES_DIRECTORY', 'ops/backups/files'), '/'),
        ])),
    ],
    'backups' => [
        'db_directory' => env('FEED_MEDIATOR_BACKUPS_DB_DIRECTORY', 'ops/backups/db'),
        'files_directory' => env('FEED_MEDIATOR_BACKUPS_FILES_DIRECTORY', 'ops/backups/files'),
    ],
    'retention' => [
        'generation_artifacts_days' => (int) env('FEED_MEDIATOR_RET_GEN_DAYS', 14),
        'preview_links_days' => (int) env('FEED_MEDIATOR_RET_PREVIEW_DAYS', 7),
        'smoke_checks_days' => (int) env('FEED_MEDIATOR_RET_SMOKE_DAYS', 30),
        'feedback_artifacts_days' => (int) env('FEED_MEDIATOR_RET_FEEDBACK_DAYS', 30),
        'qa_bundles_days' => (int) env('FEED_MEDIATOR_RET_QA_BUNDLES_DAYS', 14),
        'runbooks_days' => (int) env('FEED_MEDIATOR_RET_RUNBOOKS_DAYS', 30),
        'ops_runs_days' => (int) env('FEED_MEDIATOR_RET_OPS_RUNS_DAYS', 45),
    ],
    'performance' => [
        'build_variant_chunk_size' => (int) env('FEED_MEDIATOR_BUILD_CHUNK_SIZE', 250),
        'xml_write_chunk_size' => (int) env('FEED_MEDIATOR_XML_CHUNK_SIZE', 250),
        'workbench_page_size' => (int) env('FEED_MEDIATOR_WORKBENCH_PAGE_SIZE', 20),
        'reconciliation_breakdown_limit' => (int) env('FEED_MEDIATOR_RECON_BREAKDOWN_LIMIT', 250),
        'storage_warning_bytes' => (int) env('FEED_MEDIATOR_STORAGE_WARN_BYTES', 2147483648),
    ],
    'security' => [
        'headers_enabled' => (bool) env('FEED_MEDIATOR_SEC_HEADERS_ENABLED', true),
        'content_security_policy' => env(
            'FEED_MEDIATOR_CONTENT_SECURITY_POLICY',
            "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none'"
        ),
        'x_frame_options' => env('FEED_MEDIATOR_X_FRAME_OPTIONS', 'DENY'),
        'x_content_type_options' => env('FEED_MEDIATOR_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'referrer_policy' => env('FEED_MEDIATOR_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'rate_limits' => [
            'admin_login_per_minute' => (int) env('FEED_MEDIATOR_ADMIN_LOGIN_PER_MINUTE', 5),
            'admin_sensitive_per_minute' => (int) env('FEED_MEDIATOR_ADMIN_SENSITIVE_PER_MINUTE', 20),
        ],
        'require_high_risk_confirmation' => (bool) env('FEED_MEDIATOR_REQUIRE_HIGH_RISK_CONFIRMATION', false),
    ],
    'deploy' => [
        'health_url' => env('FEED_MEDIATOR_DEPLOY_HEALTH_URL', '/health'),
        'smoke_feed_profile_id' => env('FEED_MEDIATOR_DEPLOY_SMOKE_FEED_PROFILE_ID'),
    ],
    'schedule' => [
        'backup_db_at' => env('FEED_MEDIATOR_BACKUP_DB_AT', '02:30'),
        'backup_files_at' => env('FEED_MEDIATOR_BACKUP_FILES_AT', '03:00'),
        'prune_at' => env('FEED_MEDIATOR_PRUNE_AT', '03:30'),
    ],
    'smoke_checks' => [
        'timeout_seconds' => (int) env('FEED_MEDIATOR_SMOKE_TIMEOUT_SECONDS', 15),
        'latency_warning_ms' => (int) env('FEED_MEDIATOR_SMOKE_LAT_WARN_MS', 3000),
    ],
    'first_pull_verification' => [
        'timeout_seconds' => (int) env('FEED_MEDIATOR_FIRST_PULL_TIMEOUT_SECONDS', 20),
        'warning_reverify_after_minutes' => (int) env('FEED_MEDIATOR_FIRST_PULL_REVERIFY_MINUTES', 30),
    ],
    'rehearsal' => [
        'preview_ttl_minutes' => (int) env('FEED_MEDIATOR_REHEARSAL_PREVIEW_TTL_MINUTES', 240),
        'allow_on_production' => (bool) env('FEED_MEDIATOR_REHEARSAL_ALLOW_ON_PRODUCTION', false),
    ],
    'reliability' => [
        'healthy_rate' => (float) env('FEED_MEDIATOR_SLO_HEALTHY_RATE', 0.98),
        'warning_rate' => (float) env('FEED_MEDIATOR_SLO_WARNING_RATE', 0.90),
        'history_windows_hours' => [24, 168],
    ],
    'hypercare' => [
        'default_hours' => (int) env('FEED_MEDIATOR_HYPERCARE_DEFAULT_HOURS', 24),
        'default_target_sla_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_TARGET_SLA_MINUTES', 240),
        'default_monitoring_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_MONITORING_CADENCE_MINUTES', 60),
        'auto_start_on_publish' => (bool) env('FEED_MEDIATOR_HYPERCARE_AUTO_START_ON_PUBLISH', true),
        'alerts' => [
            'escalate_after_minutes' => (int) env('FEED_MEDIATOR_ALERT_ESCALATE_MINUTES', 15),
            'mail_enabled' => (bool) env('FEED_MEDIATOR_ALERT_MAIL_ENABLED', false),
        ],
        'phases' => [
            'first_24h' => [
                'smoke_checks_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_SMOKE_CADENCE', 60),
                'first_pull_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_FIRST_PULL_CADENCE', 180),
                'sync_warning_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_SYNC_WARN', 120),
                'sync_critical_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_SYNC_CRIT', 240),
                'feedback_spike_window_hours' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_FEEDBACK_WINDOW', 6),
                'feedback_spike_warning_count' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_FEEDBACK_WARN', 5),
                'feedback_spike_critical_count' => (int) env('FEED_MEDIATOR_HYPERCARE_24H_FEEDBACK_CRIT', 10),
            ],
            'first_72h' => [
                'smoke_checks_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_SMOKE_CADENCE', 180),
                'first_pull_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_FIRST_PULL_CADENCE', 360),
                'sync_warning_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_SYNC_WARN', 240),
                'sync_critical_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_SYNC_CRIT', 480),
                'feedback_spike_window_hours' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_FEEDBACK_WINDOW', 12),
                'feedback_spike_warning_count' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_FEEDBACK_WARN', 10),
                'feedback_spike_critical_count' => (int) env('FEED_MEDIATOR_HYPERCARE_72H_FEEDBACK_CRIT', 20),
            ],
            'steady' => [
                'smoke_checks_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_SMOKE_CADENCE', 360),
                'first_pull_cadence_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_FIRST_PULL_CADENCE', 720),
                'sync_warning_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_SYNC_WARN', 480),
                'sync_critical_after_minutes' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_SYNC_CRIT', 960),
                'feedback_spike_window_hours' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_FEEDBACK_WINDOW', 24),
                'feedback_spike_warning_count' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_FEEDBACK_WARN', 12),
                'feedback_spike_critical_count' => (int) env('FEED_MEDIATOR_HYPERCARE_STEADY_FEEDBACK_CRIT', 24),
            ],
        ],
        'policies' => [
            'publish_delta_anomaly' => [
                'warning_pct' => (float) env('FEED_MEDIATOR_PUBLISH_DELTA_WARN_PCT', 15),
                'critical_pct' => (float) env('FEED_MEDIATOR_PUBLISH_DELTA_CRIT_PCT', 30),
            ],
            'ready_items_drop' => [
                'warning_pct' => (float) env('FEED_MEDIATOR_READY_DROP_WARN_PCT', 10),
                'critical_pct' => (float) env('FEED_MEDIATOR_READY_DROP_CRIT_PCT', 20),
            ],
            'queue_lag' => [
                'warning_failed_jobs' => (int) env('FEED_MEDIATOR_QUEUE_WARN_FAILED_JOBS', 1),
                'critical_failed_jobs' => (int) env('FEED_MEDIATOR_QUEUE_CRIT_FAILED_JOBS', 3),
                'warning_backlog' => (int) env('FEED_MEDIATOR_QUEUE_WARN_BACKLOG', 10),
                'critical_backlog' => (int) env('FEED_MEDIATOR_QUEUE_CRIT_BACKLOG', 25),
            ],
            'feed_url_latency' => [
                'warning_ms' => (int) env('FEED_MEDIATOR_LATENCY_WARN_MS', 3000),
                'critical_ms' => (int) env('FEED_MEDIATOR_LATENCY_CRIT_MS', 6000),
            ],
            'feedback_backlog' => [
                'warning_count' => (int) env('FEED_MEDIATOR_FEEDBACK_BACKLOG_WARN', 10),
                'critical_count' => (int) env('FEED_MEDIATOR_FEEDBACK_BACKLOG_CRIT', 25),
            ],
        ],
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
