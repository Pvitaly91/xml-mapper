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
- cache status
- scheduler heartbeat
- worker heartbeat
- failed jobs count
- due source/build/publish counts
- last successful sync/build/publish timestamps

The endpoint becomes degraded when:

- database or cache checks fail
- scheduler heartbeat is stale
- worker heartbeat is stale for async queue mode
- failed jobs count reaches the configured threshold

## Failed Jobs

Inspect:

```bash
php artisan queue:failed
```

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
3. Refresh caches.
4. Restart queue workers.
5. Confirm scheduler is active.
6. Check `/health`.
7. Open `/admin` and confirm ops status is healthy.
