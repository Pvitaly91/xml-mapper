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
- source connection CRUD, test connection and manual sync
- feed profile CRUD, build, publish and public feed endpoint
- category, attribute and value mappings
- feed item workflow and revalidation
- dual source drivers via `source_connections.driver`:
  - `prom_yml`
  - `prom_api`
- source import -> normalize -> build -> publish pipeline
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

After every pull or deploy that includes schema changes, run `php artisan migrate` before opening `/admin`.

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

## Environment Readiness

Check the local environment before opening the admin area:

```bash
php artisan app:doctor
```

If `/admin` opens in `setup_required` mode:

1. Run `php artisan migrate`
2. Run `php artisan app:doctor`
3. Refresh `/admin`

The setup screen shows the exact missing tables when the schema is incomplete.

## Source Drivers

`source_connections.driver` resolves source imports through a dedicated driver layer:

- `prom_yml`: existing XML/YML download + parse path
- `prom_api`: Prom public API import through `GET /groups/list` and `GET /products/list`

Existing YML connections continue to work after migration. Prom API connections store:

- `api_base_url`
- `api_token` (encrypted)
- `api_version`
- connection check metadata
- last sync status / summary metadata

## Prom API Source Connection

Create a Prom API source connection in `/admin/source-connections/create`:

1. Choose `Prom API` as the driver.
2. Fill `API base URL` and `API token`.
3. Keep `API version` as `v1` unless Prom provides a newer contract for your account.
4. Optional JSON settings in `Settings / options JSON`:
   - `locale`: response locale header, example `uk`
   - `page_limit`: pagination batch size, example `100`
   - `max_pages`: safety cap, example `500`
   - `default_vendor`: fallback vendor/brand when Prom read API does not expose one
5. Save and run `Test connection`.

Prom references:

- [support.prom.ua token management](https://support.prom.ua/hc/uk/articles/360020350478-%D0%A3%D0%BF%D1%80%D0%B0%D0%B2%D0%BB%D1%96%D0%BD%D0%BD%D1%8F-API-%D1%82%D0%BE%D0%BA%D0%B5%D0%BD%D0%B0%D0%BC%D0%B8-%D0%B2-%D0%BA%D0%B0%D0%B1%D1%96%D0%BD%D0%B5%D1%82%D1%96-%D0%BA%D0%BE%D0%BC%D0%BF%D0%B0%D0%BD%D1%96%D1%97)
- [Prom public API docs](https://public-api.docs.prom.ua/)

## Test Connection

Admin:

- open a source connection
- click `Test connection`

CLI:

```bash
php artisan source:test 1
```

For `prom_api`, the test verifies token-based access to both `Groups` and `Products` endpoints and stores:

- `last_connection_check_at`
- `last_connection_check_status`
- `last_connection_check_message`

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

## Prom API Environment

Optional env/config overrides for the Prom API driver:

```dotenv
PROM_API_BASE_URL=https://my.prom.ua
PROM_API_VERSION=v1
PROM_API_TIMEOUT_SECONDS=30
PROM_API_CONNECT_TIMEOUT_SECONDS=10
PROM_API_RETRY_TIMES=3
PROM_API_RETRY_BACKOFF_MS=250
PROM_API_PAGE_LIMIT=100
PROM_API_MAX_PAGES=500
PROM_API_LOCALE=uk
```

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

Manual driver-aware sync for one connection:

```bash
php artisan source:sync 1
```

The sync workflow is:

- `prom_yml`: download XML -> parse -> normalize
- `prom_api`: fetch paginated groups/products -> cache raw JSON snapshot -> normalize

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
- schema readiness
- cache check
- scheduler heartbeat
- worker heartbeat
- failed jobs count
- queue mode
- broken Prom API auth count
- due source connections count
- due feed build count
- due feed publish count
- last successful sync / build / publish timestamps

When required tables are missing, `/health` returns `setup_required` with `schema_ready`, `missing_tables` and `setup_required` instead of throwing an exception.

The admin dashboard exposes the same ops signals for the current shop plus stale/degraded indicators. If an active Prom API connection is in `auth_failed`, the dashboard and `/health` surface it explicitly. If the schema is incomplete, `/admin` remains available and renders a setup-required state with missing tables and next steps.

## Prom API Assumptions

Prom API handling localizes uncertain contract details in the adapter/client layer:

1. Pagination uses `last_id` with descending IDs, so the importer advances with `min(current_page_ids) - 1`.
2. Read endpoints documented in Prom public API are `GET /groups/list` and `GET /products/list`.
3. Product variations are grouped with `variation_group_id`, falling back to `variation_base_id` or the product ID.
4. The documented read payload does not expose arbitrary custom product attributes. The importer maps documented product fields like presence, status, measure unit, category and SKU into normalized source attributes.
5. If vendor/brand is missing in the read payload, the importer uses `options.default_vendor`; if it is not set, it falls back to the shop name so the feed validation pipeline can stay operational.

## Troubleshooting Prom API

Auth failures:

- run `php artisan source:test {id}`
- confirm the token has `Products` and `Groups` read access in Prom
- rotate the token if Prom reports `401` or `403`

Rate limit / remote errors:

- inspect Laravel logs for `prom_api.request`
- check `Retry-After`, `http_status`, `path` and retry metadata
- lower `page_limit` or re-run later if Prom is throttling

Import / payload errors:

- inspect the latest `source_imports.meta`
- inspect the cached raw snapshot in `storage/app/imports/prom/...`
- compare payload shape against [Prom public API docs](https://public-api.docs.prom.ua/)

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
