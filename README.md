# XML Mapper

Laravel 12 service that mediates between Prom source feeds and Kasta output feeds.

The application stores normalized source data locally, validates exportability, builds cached XML generations, publishes a stable public feed URL, and exposes a Blade-based admin UI for mappings and operations.

## Stack

- PHP 8.2+
- Laravel 12
- MySQL
- Redis cache and queues
- Blade admin UI
- Queue-driven backend pipeline

## Implemented Scope

- session-based admin auth for `/admin/*`
- Gate-protected admin area
- source connection CRUD and manual sync
- feed profile CRUD, build, publish and public feed endpoint
- category, attribute and value mappings
- feed item workflow and revalidation
- parser -> normalize -> build -> publish pipeline
- production scheduler/queue orchestration with locks
- `/health` ops visibility and admin dashboard ops block
- file-driven Kasta dictionary imports with history, dry-run and reimport

## Local Setup

1. Install dependencies:

```bash
composer install
```

2. Copy environment and generate the app key:

```bash
copy .env.example .env
php artisan key:generate
```

3. Configure MySQL and Redis in `.env`.

4. Run migrations:

```bash
php artisan migrate
```

5. Load sample Kasta dictionaries:

```bash
php artisan kasta:import-dictionaries
```

6. Create a local admin user and shop:

```bash
php artisan admin:bootstrap
```

7. Start the HTTP server:

```bash
php artisan serve
```

Admin login is available at `/admin/login`.

## Queue Worker

The production queue split is:

- `imports`
- `normalization`
- `feeds`
- `dictionaries`

Run a local worker with Redis:

```bash
php artisan queue:work redis --queue=imports,normalization,feeds,dictionaries --sleep=3 --tries=3 --timeout=1800
```

Worker heartbeat is recorded from the queue loop. If the worker is not running or the heartbeat goes stale, `/health` becomes degraded.

## Scheduler

Run the Laravel scheduler every minute:

```bash
php artisan schedule:run
```

Scheduled orchestration is defined in [`routes/console.php`](routes/console.php):

- `source:sync --due --queue`
- `feed:build --due --publish --queue`
- `feed:publish --due --queue`

All scheduled commands are protected with overlap guards. Due queue dispatch is also protected with cache locks, so repeated `schedule:run` calls do not enqueue duplicate work for the same source connection or feed profile.

## Due Sync / Build / Publish Workflow

### Due source sync

`source:sync --due` resolves active source connections with `next_sync_at` missing or in the past.

### Due feed build

`feed:build --due` resolves active feed profiles with `auto_build=true` and `next_build_at` missing or in the past.

### Due feed publish

`feed:publish --due` resolves active feed profiles when a publishable generation exists and at least one of these is true:

- there is a newer built generation than `published_generation_id`
- `published_path` is empty or the published file is missing
- the profile has never been published

Build, publish and sync execution are protected with distributed cache locks. Build is idempotent for the same `source_import_id`, and publish is idempotent for an already published generation with an existing public file.

## Dictionary Import

Legacy sample-bundle import remains available for local dev:

```bash
php artisan kasta:import-dictionaries
php artisan kasta:reimport-dictionaries
```

Production file-driven imports use per-type commands:

```bash
php artisan kasta:dictionary:import kasta_categories --file=database/samples/kasta-dictionaries/kasta_categories.json
php artisan kasta:dictionary:import kasta_attributes --file=database/samples/kasta-dictionaries/kasta_attributes.csv --format=csv
php artisan kasta:dictionary:import kasta_attribute_values --dry-run
php artisan kasta:dictionary:reimport-latest size_grids
```

Available flags:

- `--file=` absolute or relative source file path
- `--format=` `json` or `csv`
- `--dry-run`
- `--deactivate-missing`
- `--queue`

Admin workflow:

- `/admin/dictionaries` keeps the legacy sample bundle shortcut
- `/admin/dictionaries/imports` provides file-driven imports, dry-run preview, filters and reimport
- `/admin/dictionaries/imports/{id}` shows import details, counts, errors and metadata

Detailed contract: [docs/kasta-dictionary-import.md](docs/kasta-dictionary-import.md)

## Health And Ops Visibility

`/health` exposes:

- database check
- cache check
- scheduler heartbeat
- worker heartbeat
- failed jobs count
- queue mode
- due source connections count
- due feed build count
- due feed publish count
- last successful sync / build / publish timestamps

The admin dashboard exposes the same ops signals for the current shop plus stale/degraded indicators.

## Production Basics

- use Redis for `CACHE_STORE` and `QUEUE_CONNECTION`
- run at least one queue worker listening to `imports,normalization,feeds,dictionaries`
- run the scheduler every minute via cron, or use `schedule:work` under systemd
- monitor `/health`
- inspect `failed_jobs` and clear/retry intentionally

Deploy artifacts:

- cron example: [`deploy/cron/schedule-run.cron`](deploy/cron/schedule-run.cron)
- supervisor worker config: [`deploy/supervisor/xml-mapper-worker.conf`](deploy/supervisor/xml-mapper-worker.conf)
- systemd worker unit: [`deploy/systemd/xml-mapper-worker.service`](deploy/systemd/xml-mapper-worker.service)
- systemd scheduler unit: [`deploy/systemd/xml-mapper-schedule-work.service`](deploy/systemd/xml-mapper-schedule-work.service)

Detailed ops runbook: [docs/operations.md](docs/operations.md)

## Public Feed

Published feeds are served from:

```text
/feeds/{public_token}.xml
```

The endpoint serves only already-built and already-published files. No runtime XML rendering happens in the public controller.

## Tests

Run the full suite:

```bash
php artisan test
```
