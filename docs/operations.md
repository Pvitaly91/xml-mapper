# Operations Runbook

## Required Runtime Services

- MySQL
- Redis
- PHP CLI
- one or more queue workers
- Laravel scheduler

## Environment

Important env variables:

- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis`
- `FEED_MEDIATOR_QUEUE_IMPORTS=imports`
- `FEED_MEDIATOR_QUEUE_NORMALIZATION=normalization`
- `FEED_MEDIATOR_QUEUE_FEEDS=feeds`
- `FEED_MEDIATOR_QUEUE_DICTIONARIES=dictionaries`
- `FEED_MEDIATOR_HEARTBEAT_STALE_AFTER_SECONDS=180`
- `FEED_MEDIATOR_FAILED_JOBS_DEGRADED_THRESHOLD=1`
- `PROM_API_BASE_URL=https://my.prom.ua`
- `PROM_API_VERSION=v1`
- `PROM_API_TIMEOUT_SECONDS=30`
- `PROM_API_CONNECT_TIMEOUT_SECONDS=10`
- `PROM_API_RETRY_TIMES=3`
- `PROM_API_RETRY_BACKOFF_MS=250`
- `PROM_API_PAGE_LIMIT=100`
- `PROM_API_MAX_PAGES=500`
- `PROM_API_LOCALE=uk`

## Scheduler

Recommended cron:

```cron
* * * * * cd /var/www/xml-mapper && php artisan schedule:run >> /var/log/xml-mapper/scheduler.log 2>&1
```

Reference file: [`deploy/cron/schedule-run.cron`](../deploy/cron/schedule-run.cron)

If you prefer a long-running scheduler instead of cron, use [`deploy/systemd/xml-mapper-schedule-work.service`](../deploy/systemd/xml-mapper-schedule-work.service) and run:

```bash
sudo systemctl enable --now xml-mapper-schedule-work.service
```

## Queue Worker

Recommended worker command:

```bash
php artisan queue:work redis --queue=imports,normalization,feeds,dictionaries --sleep=3 --tries=3 --timeout=1800 --max-time=3600
```

Relevant source commands:

```bash
php artisan source:test {sourceConnectionId}
php artisan source:sync {sourceConnectionId}
php artisan source:sync --due --queue
```

Supervisor config:

- [`deploy/supervisor/xml-mapper-worker.conf`](../deploy/supervisor/xml-mapper-worker.conf)

Systemd unit:

- [`deploy/systemd/xml-mapper-worker.service`](../deploy/systemd/xml-mapper-worker.service)

Enable with systemd:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now xml-mapper-worker.service
```

## Restart / Reload

Reload code after deploy:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart xml-mapper-worker:*
```

Systemd:

```bash
sudo systemctl restart xml-mapper-worker.service
sudo systemctl restart xml-mapper-schedule-work.service
```

## Health Monitoring

`/health` returns:

- database status
- schema readiness and missing tables
- cache status
- scheduler heartbeat
- worker heartbeat
- failed jobs count
- broken Prom API auth count
- due source/build/publish counts
- last successful sync/build/publish timestamps

The endpoint becomes degraded when:

- database or cache checks fail
- scheduler heartbeat is stale
- worker heartbeat is stale for async queue mode
- failed jobs count reaches the configured threshold
- an active Prom API source connection is in `auth_failed`

The endpoint returns `setup_required` when the database connection is available but required application tables are still missing.

## Pilot Publish Checklist

Before the first real Kasta publish for a feed profile:

1. Run `php artisan source:test {sourceConnectionId}` if the source uses Prom API.
2. Run `php artisan source:sync {sourceConnectionId}` and confirm the latest import is `normalized`.
3. Import or refresh Kasta dictionaries.
4. Open the feed profile and check `Pilot Readiness`.
5. Build the generation.
6. Review the generation diff, ready/invalid/excluded counts, and publish-guard reasons.
7. Open several feed-item diagnostics pages and confirm required attributes, XML preview, images, vendor code, color and size.
8. Publish normally. Use force publish only after confirming the blocked reasons are understood and accepted.

## Failed Jobs

Inspect:

```bash
php artisan queue:failed
```

Prom API troubleshooting:

1. Run `php artisan source:test {id}` to isolate auth/connectivity from normalization.
2. If status is `auth_failed`, rotate the token in Prom and confirm `Products` and `Groups` read access.
3. If status is `rate_limited` or `remote_error`, inspect Laravel logs for `prom_api.request` metadata.
4. If sync fails with `invalid_payload`, inspect the cached raw snapshot in `storage/app/imports/prom/...` and compare it with [Prom public API docs](https://public-api.docs.prom.ua/).
5. In admin and `/health`, watch `broken_prom_api_connections_count` and the latest connection-check message.

Export troubleshooting:

1. If build completes with too many invalid items, open `/admin/feed-profiles/{profile}` and review `Pilot Readiness`.
2. Use feed-item filters for missing category mapping, missing attribute mapping, missing value mapping, missing images, or invalid color/size.
3. On an item details page, read `Operator Summary`, `Required Attribute Diagnostics`, `Normalized Export Snapshot` and `XML Preview`.
4. If publish is blocked, compare `minimum_ready_items`, `maximum_invalid_ratio` and `block_publish_on_critical_conformance` with the current generation summary.
5. Force publish only when the operator intentionally accepts the remaining risks.

Retry selected jobs:

```bash
php artisan queue:retry all
```

Delete failed job rows only when the failure has been understood:

```bash
php artisan queue:flush
```

## Deployment Checklist

1. Deploy code.
2. Run `php artisan migrate --force`.
3. Run `php artisan app:doctor`.
4. Refresh caches.
5. Restart queue workers.
6. Confirm scheduler is active.
7. Check `/health`.
8. Open `/admin` and confirm ops status is healthy.

## Local Setup Recovery

If `/admin` shows `setup_required`:

1. Run `php artisan migrate`
2. Run `php artisan app:doctor`
3. Review the missing tables reported by the command
4. Reload `/admin`
