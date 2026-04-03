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
- Kasta export conformance layer with diagnostics, XML preview and generation diff
- publish guardrails and pilot-readiness checks on feed profiles
- release lifecycle with candidate/approve/publish/rollback, smoke checks, audit trail and operator reports
- guided shop onboarding wizard and per-shop go-live control panel
- unresolved mappings workbench with bulk helpers and confirmation step
- reusable mapping preset export/import with dry-run preview
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

Alternative local demo bootstrap with source connection + default pilot profile:

```bash
php artisan shop:bootstrap --driver=prom_yml --source-url=tests/Fixtures/prom_sample.yml
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
- `feed:build --due --queue`
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

`feed:publish --due` resolves active feed profiles when an approved or already-published generation with a build file exists and at least one of these is true:

- there is a newer approved generation than `published_generation_id`
- `published_path` is empty or the published file is missing
- the profile has never been published but an approved candidate exists

Build, publish and sync execution are protected with distributed cache locks. Build is idempotent for the same `source_import_id`, and publish is idempotent for an already published generation with an existing public file.

## Kasta Export Conformance

Build now evaluates every `feed_item` through three explicit layers:

- source validation
- mapping completeness
- export conformance

Resulting item statuses:

- `pending`
- `invalid_source`
- `invalid_mapping`
- `invalid_conformance`
- `ready`
- `excluded`
- `published`

The conformance layer checks:

- category mapping exists
- required Kasta attributes for the mapped category are satisfied
- missing attribute mapping vs missing value mapping vs missing source value are separated
- vendor, article, color and size are normalized centrally
- pictures satisfy `minimum_pictures` and remain valid URLs
- export key stability is preserved through the existing stable offer ID pipeline

## Feed Item Diagnostics

`/admin/feed-profiles/{profile}/feed-items/{item}` now shows:

- source product and source variant snapshots
- normalized export snapshot
- mapped category
- mapped attributes and value resolution
- required attribute diagnostics
- XML preview fragment for the future Kasta-ready offer
- active validation/conformance errors

Operator-facing diagnostics distinguish:

- missing category mapping
- missing attribute mapping
- missing value mapping
- missing required source value
- invalid color/size
- missing images

## Feed Profile Export Settings

`/admin/feed-profiles/{profile}/edit` exposes export-specific settings on top of the existing profile settings JSON:

- `publish_guard_enabled`
- `minimum_ready_items`
- `maximum_invalid_ratio`
- `block_publish_on_critical_conformance`
- `minimum_pictures`
- existing `include_unavailable`, `currency`, `language`

These settings are stored in `feed_profiles.settings`.

## Release Workflow

Release actions are centralized in the release service layer and exposed in the admin Release Center plus artisan commands.

Generation lifecycle:

- `built`
- `candidate`
- `approved`
- `published`
- `superseded`
- `rolled_back`
- `publish_failed`

Manual commands:

```bash
php artisan feed:approve {generationId}
php artisan feed:publish {feedProfileId?} {generationId?} --reason="..." --force
php artisan feed:rollback {feedProfileId} --to-generation={generationId} --reason="..."
php artisan feed:smoke-check {feedProfileId?} {generationId?} --latest-published
```

Admin workflow:

1. Open `/admin/feed-profiles/{profile}/release-center`
2. Mark the built generation as `candidate`
3. Approve the generation
4. Review readiness, guardrails, diff and invalid-item reports
5. Publish normally or force publish with an explicit reason
6. Re-run smoke check or roll back if needed

Every manual action records an audit event with user, action, reason and context metadata.

## Publish Guard, Diff And Smoke Checks

Every built generation stores summary metadata and a diff against the latest published generation:

- added items
- removed items
- changed items
- changed fields for `price`, `availability`, `categoryId`, `vendorCode`

Publish guardrails can block publication when:

- ready items are below the configured threshold
- invalid ratio exceeds the configured maximum
- critical conformance errors remain in the built generation

Admin can still force publish manually when an operator intentionally overrides the guard.

After publish, the system runs a smoke check against the public XML URL and stores:

- status
- checked timestamp
- latency
- HTTP status / content type
- offers count
- category count
- response checksum vs generation checksum
- warnings and errors

Manual re-run is available in admin and via `feed:smoke-check`.

## Release Center And Reports

`/admin/feed-profiles/{profile}/release-center` provides:

- generations list with build/release/smoke status
- generation details page with readiness checks, publish guard result, diff and smoke-check result
- approve / publish / force publish / rollback / rerun smoke check actions
- publish-block reason visibility when readiness fails

Downloadable operator reports:

- invalid items CSV/JSON
- generation diff JSON
- release readiness JSON

Invalid-item reports include item IDs, product/variant identifiers, source category, mapped category, status and exact blocking reasons.

## Shop Onboarding And Go-Live Control

New-shop onboarding lives at:

- `/admin/onboarding`

The wizard persists progress and guides the operator through:

1. create or update the shop
2. choose `prom_yml` or `prom_api`
3. configure and test the source connection
4. import Kasta dictionaries
5. create the default feed profile
6. run the first sync
7. run automap and mapping suggestions
8. build the first release candidate
9. open the release center

For day-to-day operations after setup, use:

- `/admin/shop/control-panel`

The go-live control panel summarizes:

- source health and last sync
- unresolved mapping counts
- ready / invalid / excluded item counts
- latest candidate / approved / published generations
- latest smoke check state
- publish blocked / allowed state

## Unresolved Mappings Workbench

The operator workbench lives at:

- `/admin/feed-profiles/{profile}/workbench`

It groups blockers into actionable queues instead of showing one long invalid-item list:

- missing category mapping
- missing attribute mapping
- missing value mapping
- missing required source values
- invalid color / size
- excluded items

Bulk helpers available from the workbench:

- bulk approve suggestions
- bulk apply exact-match value mappings
- bulk exclude selected items with confirmation
- bulk revalidate selected items
- rebuild the current candidate with confirmation

## Mapping Presets

Reusable mapping presets live at:

- `/admin/feed-profiles/{profile}/mapping-presets/import`
- `/admin/feed-profiles/{profile}/mapping-presets/export`

Preset JSON includes:

- category mappings
- attribute mappings
- value mappings
- feed-profile export settings

Import supports:

- dry-run preview
- `skip_existing`
- `overwrite_existing`
- `merge_if_safe`

## Pilot Workflow

Recommended first pilot run:

1. Open `/admin/onboarding` and create the shop.
2. Choose the source driver and configure the source connection.
3. Run `Test connection`.
4. Import Kasta dictionaries.
5. Create the default feed profile.
6. Run the first sync.
7. Open the unresolved workbench and close missing category / attribute / value blockers.
8. Build the first release candidate.
9. Open the go-live control panel and confirm unresolved counts are acceptable.
10. Open the feed profile `Release center`.
11. Mark the new generation as candidate and approve it.
12. Review readiness, diff, invalid-item report and a few feed-item diagnostics/XML previews.
13. Publish normally.
14. Review the automatic smoke-check result on the generation details page.
15. If publish is blocked, fix the listed issues or use force publish only with an explicit operator reason.
16. If the published URL fails smoke checks, use rollback intentionally and record the rollback reason.

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

Kasta export assumptions:

1. Stable offer IDs remain the authoritative export key; the new conformance layer does not replace the existing stable offer ID pipeline.
2. Article normalization removes internal whitespace and uppercases the value before export diagnostics.
3. Color normalization keeps the human-readable text but canonicalizes case/spacing for comparisons.
4. Size normalization uppercases the display value for stable comparison.
5. Required-attribute enforcement is driven from imported Kasta dictionaries, not hardcoded in Blade or controllers.

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

Export / conformance errors:

- open the feed-item details page and review `Operator Summary`, `Required Attribute Diagnostics` and `Normalized Export Snapshot`
- filter `/admin/feed-profiles/{profile}/feed-items` by missing mapping, missing value mapping, missing images, or invalid color/size
- open the feed profile and review `Pilot Readiness`, generation diff, and publish-guard reasons before publishing
- open the `Release center` and inspect invalid-item report, readiness report and generation details before approving

Release troubleshooting:

- if publish is blocked, review the `blocking_issues` list in generation readiness and compare it with the audit event `publish_blocked`
- if force publish was used, confirm the override reason was recorded and review the follow-up smoke check immediately
- if smoke check fails, compare `http_status`, `content_type`, `offers_total`, `categories_total` and checksum mismatch details on the generation page
- if rollback is needed, use the admin rollback action or `feed:rollback` with an explicit reason; the system does not auto-rollback on its own

## Merchant Pilot Acceptance Workflow

Use this workflow for the first real merchant pilot:

1. onboard the shop and finish unresolved mappings
2. build the candidate generation
3. open `/admin/feed-profiles/{profile}/acceptance`
4. generate a signed preview link with TTL and share the candidate XML
5. download the QA bundle ZIP and review summary, invalid items, diff, readiness and release notes
6. record internal or client sign-off
7. verify publish window and freeze mode status
8. approve and publish in the allowed window
9. confirm the post-publish smoke check
10. if needed, roll back from the release center with an explicit reason

Candidate preview workflow:

- preview URLs are separate from `/feeds/{public_token}.xml`
- they are signed, expiring and revocable
- they always serve the selected generation file, never the currently published feed
- operators can rerun smoke checks against preview links before publish

QA bundle generation:

- admin: generation details or acceptance screen -> `Download QA bundle`
- CLI: `php artisan feed:qa-bundle {generationId}`
- bundle contents:
  - `candidate.xml`
  - `summary.json`
  - `invalid-items.csv`
  - `generation-diff.json`
  - `readiness.json`
  - `smoke-check-summary.json`
  - `release-notes.txt`

Sign-off workflow:

- admin: acceptance screen or generation details -> `Record sign-off`
- CLI: `php artisan feed:signoff {generationId} {status} --note= --reviewer=`
- statuses:
  - `pending_review`
  - `internal_approved`
  - `client_review`
  - `client_approved`
  - `rejected`
  - `superseded`
- if `signoff_required=true` on the feed profile, publish is blocked until the configured status is reached unless force publish is used with a reason

Publish windows and freeze mode:

- feed profile settings now support:
  - `publish_window_enabled`
  - `publish_window_days`
  - `publish_window_start`
  - `publish_window_end`
  - `publish_window_timezone`
  - `freeze_mode`
- freeze blocks auto publish and blocks manual publish unless force publish is used with a reason
- CLI:
  - `php artisan feed:preview-link {generationId} --ttl=1440`
  - `php artisan feed:freeze {feedProfileId} --on --reason="pilot freeze"`

Rollback journal:

- preview link creation/revoke, sign-off transitions, QA bundle generation, blocked publish, publish failures, smoke-check reruns, rollback and freeze toggles are all stored in `feed_release_events`
- use the release center audit trail as the operator incident journal for pilot go-live

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
