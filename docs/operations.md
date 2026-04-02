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
php artisan shop:bootstrap --driver=prom_yml --source-url=tests/Fixtures/prom_sample.yml
php artisan feed:approve {generationId}
php artisan feed:publish {feedProfileId?} {generationId?}
php artisan feed:rollback {feedProfileId} --to-generation={generationId} --reason="..."
php artisan feed:smoke-check {feedProfileId?} {generationId?} --latest-published
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

1. Open `/admin/onboarding`.
2. Create or update the shop.
3. Choose the source driver and configure the source connection.
4. Run `Test connection`.
5. Run `php artisan source:sync {sourceConnectionId}` or sync from the onboarding wizard.
6. Import or refresh Kasta dictionaries.
7. Create the default feed profile if it is still missing.
8. Open `/admin/feed-profiles/{profile}/workbench` and close unresolved mapping blockers.
9. Build the first candidate.
10. Open `/admin/shop/control-panel` and confirm unresolved counts plus latest readiness state look sane.
11. Open `/admin/feed-profiles/{profile}/release-center`.
12. Mark the generation as candidate and approve it.
13. Review readiness blockers/warnings, diff, invalid-item report and publish-guard reasons.
14. Open several feed-item diagnostics pages and confirm required attributes, XML preview, images, vendor code, color and size.
15. Publish normally. Use force publish only after confirming the blocked reasons are understood and accepted, and always record the reason.
16. Review the automatic smoke check on the generation details page.
17. If the smoke check fails, inspect the failure details first. Roll back only by explicit operator decision.

## Onboarding Wizard

The first-shop guided flow lives at `/admin/onboarding`.

Use it to:

- create the shop
- choose `prom_yml` or `prom_api`
- configure and test the source connection
- ensure dictionaries are present
- create the default feed profile
- run the first sync
- run automap and suggestions
- build the first release candidate
- jump directly into the release center

The wizard stores progress in onboarding state and shows blocking reasons plus next steps for incomplete stages.

## Go-Live Control Panel

Daily operator control panel:

- `/admin/shop/control-panel`

It aggregates:

- source health
- last sync state
- unresolved mapping counts
- ready / invalid / excluded item counts
- latest candidate / approved / published generations
- latest smoke check result
- publish blocked / allowed state

Use this page as the default morning check before opening the release center.

## Unresolved Mappings Workbench

Operator queue:

- `/admin/feed-profiles/{profile}/workbench`

Problem groups:

- missing category mapping
- missing attribute mapping
- missing value mapping
- missing required source values
- invalid color / size
- excluded items

Bulk helpers:

- bulk approve suggestions
- bulk apply exact-match value mappings
- bulk exclude selected items with confirmation
- bulk revalidate selected items
- rebuild the current candidate with confirmation

The workbench is intended to replace blind paging through thousands of invalid items.

## Release Center

The release center is the operator entry point for real pilot publishes:

- page: `/admin/feed-profiles/{profile}/release-center`
- generation details: `/admin/feed-profiles/{profile}/generations/{generation}`

Use it to:

- review generation counts and release status
- approve the release candidate
- publish or force publish with a reason
- rerun smoke check
- roll back to a previous generation
- download invalid-item, diff and readiness reports

Blocked publish is expected to be visible there with explicit reasons. Do not bypass it blindly.

## Smoke Checks

Smoke checks run automatically after publish and rollback, and can also be started manually from admin or CLI.

They verify:

- public URL is reachable
- HTTP status is `200`
- content type looks like XML
- XML is well formed
- feed is not empty
- offers and categories are present
- counts and checksum still match the published generation
- latency stays within warning thresholds

Persisted smoke-check data includes status, latency, counts, checksum summary, warnings and errors.

Interpretation:

- `ok`: published URL matches the generation and basic XML checks pass
- `warning`: XML is valid but response time crossed the warning threshold
- `failed`: URL/content/count/checksum validation failed and needs operator action

## Blocked Publish / Force Publish / Rollback

Normal publish is blocked when readiness detects issues such as:

- source sync is stale
- dictionaries are missing
- mappings or critical conformance are incomplete
- generation is not approved
- publish guard thresholds fail

Force publish is allowed only with an explicit reason. The override is stored in the audit trail and should be followed by an immediate smoke-check review.

Rollback is always manual. The system records the operator, reason and from/to generation IDs.

## Reports

Operator reports available from admin:

- invalid items CSV/JSON
- generation diff JSON
- release readiness JSON
- mapping preset JSON export/import dry-run for similar shops

Use them to hand off actionable issues to merchandising or integration operators.

## Mapping Presets

Preset routes:

- `/admin/feed-profiles/{profile}/mapping-presets/export`
- `/admin/feed-profiles/{profile}/mapping-presets/import`

Preset import supports:

- dry-run preview
- `skip_existing`
- `overwrite_existing`
- `merge_if_safe`

Recommended use:

1. export mappings from the first working shop
2. import into the next similar shop
3. run dry-run first
4. review unresolved rows and collisions
5. import only after confirming the collision strategy

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
2. Use `/admin/feed-profiles/{profile}/workbench` first; only fall back to raw feed-item filters when you need record-level detail.
3. On an item details page, read `Operator Summary`, `Required Attribute Diagnostics`, `Normalized Export Snapshot` and `XML Preview`.
4. If publish is blocked, compare `minimum_ready_items`, `maximum_invalid_ratio` and `block_publish_on_critical_conformance` with the current generation summary and readiness report.
5. If smoke check fails, inspect HTTP status, XML parse errors, offers/categories counts and checksum mismatch before deciding whether to republish or roll back.
6. Force publish only when the operator intentionally accepts the remaining risks.

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
