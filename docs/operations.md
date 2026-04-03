# Operations Runbook

## Required Runtime Services

- MySQL
- Redis
- PHP CLI
- one or more queue workers
- Laravel scheduler
- persistent shared storage for feeds, previews, bundles, backups and runbooks

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
- `FEED_MEDIATOR_BACKUPS_DB_DIRECTORY=ops/backups/db`
- `FEED_MEDIATOR_BACKUPS_FILES_DIRECTORY=ops/backups/files`
- `FEED_MEDIATOR_RET_GEN_DAYS=14`
- `FEED_MEDIATOR_RET_PREVIEW_DAYS=7`
- `FEED_MEDIATOR_RET_SMOKE_DAYS=30`
- `FEED_MEDIATOR_RET_FEEDBACK_DAYS=30`
- `FEED_MEDIATOR_RET_QA_BUNDLES_DAYS=14`
- `FEED_MEDIATOR_RET_RUNBOOKS_DAYS=30`
- `FEED_MEDIATOR_ADMIN_LOGIN_PER_MINUTE=5`
- `FEED_MEDIATOR_ADMIN_SENSITIVE_PER_MINUTE=20`
- `FEED_MEDIATOR_DEPLOY_HEALTH_URL=/health`

## Scheduler

Recommended cron:

```cron
* * * * * cd /var/www/xml-mapper/current && php artisan schedule:run >> /var/log/xml-mapper/scheduler.log 2>&1
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
php artisan feed:cutover-status {feedProfileId}
php artisan feed:first-pull-verify {feedProfileId} --generation={generationId}
php artisan feedback:import csv --file=/abs/path/feedback.csv --feed-profile={feedProfileId} --dry-run
php artisan feedback:reconcile {feedProfileId}
php artisan feed:runbook {feedProfileId}
php artisan ops:preflight-production
php artisan ops:backup-db
php artisan ops:backup-files
php artisan ops:prune
php artisan ops:benchmark-feed {feedProfileId}
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

## Production Topology

Recommended server topology:

- `nginx` with [`deploy/nginx/xml-mapper.conf`](../deploy/nginx/xml-mapper.conf)
- dedicated `php-fpm` pool with [`deploy/php-fpm/www.conf.example`](../deploy/php-fpm/www.conf.example)
- MySQL for application data
- Redis for queues, cache and locks
- queue worker under systemd or supervisor
- scheduler under cron or dedicated process
- shared storage mounted at `/var/www/xml-mapper/shared/storage`

Release directories:

- `/var/www/xml-mapper/releases/<release>`
- `/var/www/xml-mapper/current`
- `/var/www/xml-mapper/shared/.env`
- `/var/www/xml-mapper/shared/storage`

## Zero-Downtime Deploy

Server-side deploy example:

```bash
APP_BASE=/var/www/xml-mapper \
DEPLOY_REPO_URL=git@github.com:Pvitaly91/xml-mapper.git \
DEPLOY_BRANCH=main \
APP_URL=https://xml-mapper.example.com \
bash scripts/deploy.sh
```

The deploy script:

1. clones the branch into a new release directory
2. links shared `.env` and `storage`
3. installs Composer production dependencies
4. runs migrations
5. caches config/routes/views
6. switches the `current` symlink
7. restarts queue workers
8. runs `ops:preflight-production`
9. checks `/health`
10. optionally runs `feed:smoke-check` when `FEED_MEDIATOR_DEPLOY_SMOKE_FEED_PROFILE_ID` is set
11. records deploy metadata in `ops_runs`

GitHub Actions:

- `.github/workflows/tests.yml` runs Pint + `php artisan test` on push and PR
- `.github/workflows/deploy-production.yml` is manual (`workflow_dispatch`) and builds a release artifact only; it does not push to the server automatically

## Rollback Procedure

Code rollback:

```bash
APP_BASE=/var/www/xml-mapper \
APP_URL=https://xml-mapper.example.com \
bash scripts/rollback.sh
```

What rollback does:

- switches `current` to the previous or explicitly selected release
- restarts workers
- reruns `ops:preflight-production`
- rechecks `/health`
- optionally reruns feed smoke check
- records rollback metadata in `ops_runs`

Important limit:

- database rollback is manual
- if the broken release shipped destructive migrations, restore from backup before reopening writes

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

## Production Preflight

Use before cutover, before deploy, and right after deploy:

```bash
php artisan ops:preflight-production
```

Checks covered:

- DB connection
- Redis connection
- required schema tables
- writable storage directories
- queue reachability
- scheduler heartbeat hint
- `APP_KEY`
- `APP_ENV` / `APP_DEBUG`
- critical config keys

Every run is stored in `ops_runs` and shown on the dashboard / operations screen.

## Backup / Restore

Database backup:

```bash
php artisan ops:backup-db
```

Files backup:

```bash
php artisan ops:backup-files
```

Restore guidance:

- DB: import the generated SQL dump into the target database, then run `php artisan ops:preflight-production`
- files: restore the ZIP contents into shared storage, then rerun preflight and smoke-checks

## Prune / Retention

Retention command:

```bash
php artisan ops:prune
```

Current prune scope:

- old non-published generation XML artifacts
- expired/revoked preview links
- old smoke-check history while keeping the latest row per generation
- old feedback import source files
- old QA bundle archives
- old runbook snapshots
- old benchmark/preflight ops runs

Audit and release history are intentionally not pruned automatically.

## Benchmark / Performance

Benchmark command:

```bash
php artisan ops:benchmark-feed {feedProfileId}
```

The benchmark stores:

- latest sync/build/publish/smoke durations
- reconciliation probe time
- feedback workbench probe time
- unresolved mappings probe time
- operations summary probe time
- peak memory usage

Use it after major catalog growth, before go-live windows, and after any query/index changes.

## Security Hardening

Admin HTTP responses now send:

- `X-Frame-Options`
- `X-Content-Type-Options`
- `Referrer-Policy`
- CSP on HTML pages
- HSTS on secure requests

Rate limits:

- login requests
- release-sensitive POST actions
- backup/prune/preflight/benchmark admin actions

Secrets handling:

- Prom API tokens are encrypted at rest
- `api_token` is excluded from flashed validation input
- token rotation should be followed by `source:test`

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
- acceptance screen: `/admin/feed-profiles/{profile}/acceptance?generation_id={generation}`

Use it to:

- review generation counts and release status
- approve the release candidate
- generate and revoke candidate preview links
- download the QA bundle ZIP
- record sign-off and review notes
- publish or force publish with a reason
- rerun smoke check
- roll back to a previous generation
- download invalid-item, diff and readiness reports

Blocked publish is expected to be visible there with explicit reasons. Do not bypass it blindly.

## Production Operations Screen

Use `/admin/feed-profiles/{profile}/operations` during the real merchant pilot window.

It centralizes:

- last sync / build / publish / preview
- current cutover status and launch window
- latest smoke check and first-pull verification
- publish window / freeze mode state
- broken source auth signal
- feedback summary and open remediation count
- recent incidents and warnings

This page is the fastest way to answer “what happened after go-live?” for one merchant.

## Candidate Preview And QA Bundle

Candidate preview:

- preview URLs are separate from the public feed token route
- they are signed and expiring, and can be revoked from admin
- they serve the selected generation XML file directly
- operators can run manual smoke checks against preview links before publish

Commands:

- `php artisan feed:preview-link {generationId} --ttl=1440`
- `php artisan feed:qa-bundle {generationId}`

QA bundle contents:

- candidate XML file
- summary JSON
- invalid-items report
- generation diff report
- readiness report
- smoke-check summary
- human-readable release notes

Use the bundle when sharing the pilot candidate with the merchant or internal QA stakeholders.

## Sign-Off Workflow

Sign-off states:

- `pending_review`
- `internal_approved`
- `client_review`
- `client_approved`
- `rejected`
- `superseded`

Rules:

- sign-off is generation-specific
- the current sign-off row is used by publish guard evaluation
- if the feed profile requires sign-off, publish is blocked until the configured state is reached
- force publish still requires a reason and is recorded in the audit journal

Command:

- `php artisan feed:signoff {generationId} {status} --note= --reviewer=`

## Publish Windows And Freeze Mode

Feed profile settings now support:

- allowed weekdays
- allowed start/end time
- publish window timezone
- freeze mode

Behavior:

- auto publish runs only when the profile is currently inside the allowed window
- freeze mode blocks auto publish completely
- manual publish is blocked while freeze is active or the window is closed unless force publish is used with a reason
- the acceptance screen and release center show `allowed now`, `next allowed window` and freeze state

Command:

- `php artisan feed:freeze {feedProfileId} --on --reason="merchant freeze"`

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

## First-Pull Verification

First-pull verification is cutover-specific and persists separately from the generic smoke-check history.

Use it when:

- the first live publish has just happened
- you need to confirm the first production fetch explicitly
- you want a rerunnable verification event after a rollback or republish

It stores:

- status
- latency
- response size
- offers/categories totals
- checksum summary
- warnings and errors

Manual trigger points:

- acceptance screen
- operations screen
- `php artisan feed:first-pull-verify {feedProfileId}`

If it fails, review the published feed immediately and decide whether to remediate and republish or to roll back.

## Blocked Publish / Force Publish / Rollback

Normal publish is blocked when readiness detects issues such as:

- source sync is stale
- dictionaries are missing
- mappings or critical conformance are incomplete
- generation is not approved
- publish guard thresholds fail

Force publish is allowed only with an explicit reason. The override is stored in the audit trail and should be followed by an immediate smoke-check review.

Rollback is always manual. The system records the operator, reason and from/to generation IDs.

## Incident Journal

`feed_release_events` is the pilot incident journal. It records:

- blocked publish
- publish failed
- preview link created or revoked
- QA bundle generated
- sign-off transitions
- freeze enabled or disabled
- smoke-check reruns and failures
- rollback

Use the release center audit table as the operator-friendly history for who did what and why.

## Reconciliation Report

`/admin/feed-profiles/{profile}/reconciliation` compares source, normalized, mapped, ready and published counts.

Use it when:

- the merchant reports fewer offers than expected
- imported feedback suggests missing products
- source sync finished but published totals did not move as expected

Download:

- JSON for detailed diagnostics
- CSV for blocker-count handoff

The report highlights top blocker codes and source-vs-published deltas.

## Manual Feedback Import And Remediation

The system does not assume any official Kasta acceptance/rejection API. Merchant feedback is handled manually.

Import surface:

- `/admin/feed-profiles/{profile}/feedback/import`
- `php artisan feedback:import csv --file=/abs/path/file.csv --feed-profile={feedProfileId}`

Formats:

- CSV
- JSON

Dry-run preview is available before persistence.

After import, use:

- `/admin/feed-profiles/{profile}/feedback-workbench`
- `php artisan feedback:reconcile {feedProfileId}`

Remediation statuses:

- `open`
- `in_progress`
- `fixed`
- `wont_fix`
- `excluded`

Typical operator loop:

1. import merchant feedback
2. filter unmatched / mapping / image / pricing / size-color issues
3. open diagnostics or mapping screens from the workbench
4. mark the remediation status
5. rebuild the candidate
6. republish intentionally when fixes are ready

## Merchant-Specific Overrides

Feed profile settings now include merchant-local export rules:

- excluded source categories
- excluded vendors / brands
- minimum price threshold
- override minimum pictures
- disabled mapped Kasta categories
- forced attribute overrides JSON
- forced value overrides JSON

These rules are profile-scoped and applied centrally by validation / conformance services.

Use them sparingly and document why they were added in the feed profile or release notes.

## Runbook Export

Generate a merchant go-live checklist snapshot with:

- `/admin/feed-profiles/{profile}/runbook`
- `php artisan feed:runbook {feedProfileId}`

The generated Markdown snapshot includes source state, mappings, candidate readiness, sign-off, publish window, first-pull verification and feedback follow-up.

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
6. If publish is blocked by sign-off, preview, freeze or publish window, resolve the blocker on the acceptance screen before retrying.
7. Force publish only when the operator intentionally accepts the remaining risks.

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
2. Run `php artisan ops:preflight-production`.
3. Run `php artisan migrate --force`.
4. Refresh caches.
5. Restart queue workers.
6. Confirm scheduler is active.
7. Check `/health`.
8. If a published feed exists, run `php artisan feed:smoke-check {feedProfileId} --latest-published`.
9. Open `/admin` and confirm ops/maintenance status is healthy.

## Daily / Weekly Service Checklist

Daily:

1. dashboard healthy
2. no broken Prom API auth
3. queue backlog acceptable
4. last backups recent
5. no retention warnings building up

Weekly:

1. run preflight manually
2. verify backup artifacts are downloadable
3. run benchmark for the busiest merchant
4. review storage growth
5. review old preview/build artifacts and prune if needed

## Local Setup Recovery

If `/admin` shows `setup_required`:

1. Run `php artisan migrate`
2. Run `php artisan app:doctor`
3. Review the missing tables reported by the command
4. Reload `/admin`

## Staging vs Production

Set the environment class explicitly:

- `FEED_MEDIATOR_ENV_CLASS=local`
- `FEED_MEDIATOR_ENV_CLASS=staging`
- `FEED_MEDIATOR_ENV_CLASS=production`

Operators should verify the environment badge before running rehearsal, force publish, rollback, freeze toggles, or feedback import.

## Staging Rehearsal Workflow

Admin:

- `/admin/feed-profiles/{profile}/rehearsal`

CLI:

```bash
php artisan feed:rehearse-launch {feedProfileId} --with-sync --with-build --with-preview --with-smoke --with-rollback-check
```

What the rehearsal covers:

1. staging-target preflight
2. source test connection
3. optional sync
4. unresolved mapping summary
5. candidate build
6. canary preview artifact
7. QA bundle generation
8. sign-off capture or verification
9. canary smoke-check
10. canary first-pull verification
11. optional rollback rehearsal

Each run is persisted in `ops_runs` as type `rehearsal`.

## Canary / Safe Publish Rehearsal

Canary rehearsal never replaces the stable public feed URL.

- it uses the preview-link subsystem with `meta.target=canary`
- smoke checks can run directly against that isolated artifact
- first-pull verification can also run against that artifact
- rollback rehearsal can validate a safe fallback artifact without publishing it

## Restore Drill Procedure

Use the restore-drill action from the profile operations screen after recent DB/files backups exist.

The drill stores:

- actor
- started / finished timestamps
- latest DB/files backup references
- checklist outcome
- markdown report artifact path

The drill is intentionally non-destructive. It verifies readiness for restore rather than executing a live restore on the running environment.

## Secret Rotation Notes

Rotation metadata is stored in `ops_runs` as type `secret_rotation`.

Supported targets:

- `prom_api_token`
- `app_secret`
- `deploy_credentials`

Rule:

- record who rotated the secret
- record when it was rotated
- record the note / ticket reference
- never store raw secret values in the repo, logs, or rotation notes

Optional extra hardening:

```dotenv
FEED_MEDIATOR_REQUIRE_HIGH_RISK_CONFIRMATION=true
```

Then force publish, rollback, freeze toggles, and non-dry-run feedback import require `confirmation=CONFIRM` together with the explicit reason.

## Reliability Summary

The dashboard and feed-profile operations screen expose rolling reliability summaries for:

- sync success rate
- build success rate
- publish success rate
- smoke-check success rate
- first-pull verification success rate

Windows:

- last 24h
- last 7d

Overall states:

- `healthy`
- `warning`
- `degraded`

## First Merchant Launch Pack

Generate from:

- `/admin/feed-profiles/{profile}/launch-pack`

The markdown pack includes:

- shop / source / dictionary summary
- unresolved mapping summary
- candidate readiness
- sign-off state
- publish window / freeze status
- preview / QA references
- cutover / rollback / first-pull plan
- feedback import plan
- operator notes
