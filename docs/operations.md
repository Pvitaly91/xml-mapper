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
- `FEED_MEDIATOR_HYPERCARE_DEFAULT_HOURS=24`
- `FEED_MEDIATOR_HYPERCARE_TARGET_SLA_MINUTES=240`
- `FEED_MEDIATOR_HYPERCARE_MONITORING_CADENCE_MINUTES=60`
- `FEED_MEDIATOR_HYPERCARE_AUTO_START_ON_PUBLISH=true`
- `FEED_MEDIATOR_ALERT_ESCALATE_MINUTES=15`
- `FEED_MEDIATOR_ALERT_MAIL_ENABLED=false`
- `FEED_MEDIATOR_HYPERCARE_24H_SMOKE_CADENCE=60`
- `FEED_MEDIATOR_HYPERCARE_24H_FIRST_PULL_CADENCE=180`
- `FEED_MEDIATOR_HYPERCARE_72H_SMOKE_CADENCE=180`
- `FEED_MEDIATOR_HYPERCARE_72H_FIRST_PULL_CADENCE=360`
- `FEED_MEDIATOR_PUBLISH_DELTA_WARN_PCT=15`
- `FEED_MEDIATOR_PUBLISH_DELTA_CRIT_PCT=30`
- `FEED_MEDIATOR_READY_DROP_WARN_PCT=10`
- `FEED_MEDIATOR_READY_DROP_CRIT_PCT=20`
- `FEED_MEDIATOR_QUEUE_WARN_FAILED_JOBS=1`
- `FEED_MEDIATOR_QUEUE_CRIT_FAILED_JOBS=3`
- `FEED_MEDIATOR_LATENCY_WARN_MS=3000`
- `FEED_MEDIATOR_LATENCY_CRIT_MS=6000`
- `FEED_MEDIATOR_FEEDBACK_BACKLOG_WARN=10`
- `FEED_MEDIATOR_FEEDBACK_BACKLOG_CRIT=25`
- `FEED_MEDIATOR_PROMOTION_SCHEMA_VERSION=1`
- `FEED_MEDIATOR_NOTIFY_DB_ENABLED=true`
- `FEED_MEDIATOR_NOTIFY_LOG_ENABLED=true`
- `FEED_MEDIATOR_NOTIFY_MAIL_ENABLED=false`
- `FEED_MEDIATOR_NOTIFY_MAIL_TO=ops@example.com`
- `FEED_MEDIATOR_NOTIFY_LOG_CHANNEL=stack`
- `FEED_MEDIATOR_NOTIFY_SUPPRESS_MINUTES=15`
- `FEED_MEDIATOR_NOTIFY_REPEAT_MINUTES=30`
- `FEED_MEDIATOR_NOTIFY_ESCALATE_MINUTES=15`
- `FEED_MEDIATOR_NOTIFY_QUIET_TZ=Europe/Kyiv`
- `FEED_MEDIATOR_NOTIFY_WEBHOOK_TIMEOUT=5`
- `FEED_MEDIATOR_NOTIFY_WEBHOOK_RETRIES=3`
- `FEED_MEDIATOR_NOTIFY_WEBHOOK_BACKOFF=60,300,900`
- `FEED_MEDIATOR_NOTIFY_RET_DAYS=30`
- `FEED_MEDIATOR_CORRELATION_HEADER=X-Correlation-ID`
- `FEED_MEDIATOR_REDACT_KEYS=authorization,token,secret,password,api_key,api_token,webhook_url`
- `FEED_MEDIATOR_ERROR_TRACKING_DRIVER=sentry`
- `FEED_MEDIATOR_ERROR_TRACKING_DSN=`

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
php artisan feed:hypercare:start {feedProfileId} --hours=24 --note="launch window"
php artisan feed:hypercare:status {feedProfileId}
php artisan feed:hypercare:close {feedProfileId} --reason="merchant stable"
php artisan promotion:snapshot {feedProfileId} --env=staging
php artisan promotion:diff {sourceFeedProfileId} {targetFeedProfileId} --source-env=staging --target-env=production
php artisan promotion:dry-run {sourceFeedProfileId} {targetFeedProfileId} --strategy=safe_merge
php artisan promotion:apply {sourceFeedProfileId} {targetFeedProfileId} --strategy=safe_merge --reason="approved staging config"
php artisan promotion:rollback {promotionRunId} --reason="restore production baseline"
php artisan pilot:plan {feedProfileId} --note="merchant pilot"
php artisan pilot:run {feedProfileId} --with-sync --with-build --with-publish --with-feedback-fixtures
php artisan pilot:resume {pilotRunId} --with-sync --with-build --with-publish --with-feedback-fixtures
php artisan pilot:abort {pilotRunId} --reason="operator abort"
php artisan pilot:evidence {pilotRunId}
php artisan pilot:status {pilotRunId}
php artisan launch:start {feedProfileId} --pilot={pilotRunId} --promotion={promotionRunId} --note="first live merchant launch"
php artisan launch:status {launchId}
php artisan launch:observe {launchId} merchant_confirmation --severity=medium --note="merchant confirmed live visibility"
php artisan launch:defect {launchId} mapping_gap --severity=high --note="live mapping issue"
php artisan launch:check {launchId}
php artisan launch:handover {launchId} --reason="stable after first live window"
php artisan launch:close {launchId} --reason="closeout complete"
php artisan feedback:import csv --file=/abs/path/feedback.csv --feed-profile={feedProfileId} --dry-run
php artisan feedback:reconcile {feedProfileId}
php artisan feed:runbook {feedProfileId}
php artisan ops:alerts:review --shop={shopId} --profile={feedProfileId}
php artisan ops:digest {feedProfileId} --date=2026-04-03
php artisan ops:handoff {feedProfileId}
php artisan ops:silence {feedProfileId} --from="2026-04-03 23:00" --to="2026-04-04 01:00" --severity=warning --reason="planned maintenance"
php artisan ops:silence:clear {feedProfileId}
php artisan ops:notify:test {channel} --target= --shop=
php artisan ops:notify:retry {deliveryId}
php artisan ops:alerts:dispatch-pending
php artisan ops:alerts:escalate-due --shop= --profile=
php artisan ops:deliveries:prune
php artisan ops:channels:status --shop=
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

Outbound delivery history has a dedicated retention path:

```bash
php artisan ops:deliveries:prune
```

The delivery retention window is controlled by `FEED_MEDIATOR_NOTIFY_RET_DAYS`. This keeps `ops_notification_deliveries` manageable without coupling external-notification cleanup to the broader artifact prune flow.

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
- webhook targets are encrypted at rest and shown through redacted labels only
- notification target fields are excluded from flashed validation input
- structured logs and optional error-tracking context redact token/secret-like keys automatically

## Notification Channels And Routing

Use `/admin/notifications` as the operator-facing Notification Center.

Supported outbound channels:

- `database`
- `log`
- `email`
- `webhook`

Route scopes:

- `global`
- `shop`
- `feed_profile`

Route controls:

- `event_family`, `event_type` and minimum severity
- channel enable/disable
- `muted_until`
- quiet hours start/end/timezone
- per-route suppression window, repeat interval, escalation delay, timeout and retry attempts

Supported event families include:

- `source_auth_broken`
- `sync_failed`
- `build_failed`
- `publish_failed`
- `smoke_failed`
- `first_pull_failed`
- `promotion_blocked`
- `signoff_blocked`
- `hypercare_critical_issue`
- `rejection_spike`
- `launch_degraded`
- `rollback_executed`

If no persisted routes exist, the system still has safe fallback database/log routing so critical operator visibility does not disappear.

## Delivery History, Dedup And Escalation

Every outbound attempt is stored in `ops_notification_deliveries` with:

- event type and family
- severity
- channel and redacted target label
- rendered summary/payload snapshot
- attempts, timestamps and redacted response metadata
- linked alert, launch, hypercare window, feed profile and pilot run when available
- correlation ID

Delivery states:

- `pending_delivery`
- `delivered`
- `acknowledged`
- `suppressed`
- `escalated`
- `resolved`
- `dropped`
- `failed`

Dedup and anti-spam rules:

- dedup keys/fingerprints are evaluated before delivery
- suppression windows keep identical alert noise from fanning out repeatedly
- repeat intervals delay re-delivery until the configured window expires
- suppressed deliveries remain visible in history and in the Notification Center
- due unacknowledged alerts can be escalated with `ops:alerts:escalate-due`

Scheduler hooks:

- `ops:alerts:dispatch-pending` retries pending deliveries whose backoff window expired
- `ops:alerts:escalate-due` escalates overdue alerts
- `ops:deliveries:prune` removes old delivery history

## Correlation IDs And Structured Logging

HTTP requests receive a correlation ID through `FEED_MEDIATOR_CORRELATION_HEADER` (`X-Correlation-ID` by default). The same ID is propagated into:

- queued jobs
- alerts/incidents
- outbound deliveries
- sync logs
- release events
- smoke checks
- first-pull verification
- launch and hypercare events

This gives operators a stable trace key for debugging one incident across admin UI, logs, delivery history and closeout reports.

Structured log context is attached to critical workflows:

- source sync
- build/publish
- smoke
- first-pull
- feedback import
- promotion
- pilot
- launch/hypercare

Optional external error tracking is supported only when configured:

- set `FEED_MEDIATOR_ERROR_TRACKING_DSN`
- keep `FEED_MEDIATOR_ERROR_TRACKING_DRIVER=sentry` or replace it with another adapter if the service binding exists
- when no DSN is present, the hook is a safe no-op

## Testing Notification Delivery

Admin actions:

- open `/admin/notifications`
- use `Send test notification` for an ad-hoc target
- use `Test route` on an existing subscription
- review the recorded delivery detail for status, attempts and correlation ID

CLI:

```bash
php artisan ops:notify:test {channel} --target= --shop=
php artisan ops:notify:retry {deliveryId}
php artisan ops:channels:status --shop=
```

Operator expectations:

1. run one test per active external route before a real live launch
2. confirm `last_test_succeeded_at` or `last_test_failed_at` changed on the route
3. if a test failed, inspect the persisted delivery and retry only after fixing the target or route policy

## RBAC, Memberships And Safe Admin Usage

Production admin access now depends on active `shop_memberships`.

Roles:

- `platform_admin`
- `shop_admin`
- `operator`
- `reviewer`
- `observer`

Operational expectations:

1. use `/admin/access` to manage memberships instead of relying on the legacy `users.role=admin` flag
2. keep operators and reviewers shop-scoped whenever possible
3. reserve `platform_admin` for cross-shop governance and break-glass operations
4. if a user is suspended or revoked, their membership state changes immediately govern admin access
5. always confirm the current shop selector before taking a release, promotion, launch or source-secret action

CLI helpers:

```bash
php artisan access:list-members --shop=
php artisan access:grant {user} {role} --shop= --by=
php artisan access:revoke {user} {role} --shop= --by=
```

## Approval Queue And Four-Eyes Rule

Sensitive and high-risk actions flow through a persisted approval queue.

Statuses:

- `pending`
- `approved`
- `rejected`
- `expired`
- `cancelled`
- `executed`

The following actions are governance-controlled:

- force publish
- rollback
- freeze toggle
- promotion apply / rollback
- secret rebind / rotation confirmation
- emergency tuning
- launch closeout override
- critical silence window creation
- destructive prune / maintenance

Operational rules:

1. staging can allow direct execution for some actions that require approval in production
2. `high_risk` production actions can require `4-eyes`, so the requester cannot self-approve
3. approval executes the persisted payload rather than accepting a second free-form request body
4. expired or rejected approvals must be recreated; they are not silently revived
5. all request / approve / reject / execute transitions are written into the governance audit trail

Approval CLI:

```bash
php artisan approval:list --status=pending
php artisan approval:approve {approvalId} --note= --by=
php artisan approval:reject {approvalId} --note= --by=
```

## Secret Governance And Compliance Reports

Secret handling for source connections and related operational targets is deliberately strict.

Rules:

1. raw secret values are not shown back in edit forms or normal detail screens
2. source credentials remain encrypted at rest
3. secret updates, rebinds and rotation confirmations are audited
4. production-sensitive secret actions can require approval through the same governance layer
5. logs, reports, notifications and flash messages redact token-like material

Use `/admin/access/compliance` for governance-grade filters by shop, user and date.

Exports:

```bash
php artisan compliance:report --shop= --user= --from= --to=
```

The compliance export is written into `FEED_MEDIATOR_GOV_REPORTS_DIRECTORY` and includes:

- governance audit rows
- approvals history
- sensitive action history
- correlation IDs where available

## Launch, Hypercare And Incident Propagation

Important live-support events now emit outbound notification candidates instead of staying only inside the admin UI:

- launch degraded
- smoke failed
- first-pull failed
- rejection spike
- rollback executed
- critical incident unresolved

Live launch and hypercare screens show:

- recent outbound deliveries
- failed deliveries
- suppressed deliveries
- escalated alerts

Closeout and evidence-oriented reports include outbound notification summaries when those deliveries are part of the operational story.

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

## Pilot Center

Use `/admin/pilot-runs` as the operator control point for the first real merchant pilot.

Available actions:

- plan a pilot run for a feed profile
- open pilot details with current state, next step and blockers
- run next step
- resume a blocked or failed run
- abort with reason
- add note / incident / override
- open rehearsal, promotion, release center, feedback remediation and hypercare screens
- download pilot reports and evidence pack

The Pilot Center is intentionally Blade/server-rendered and delegates all workflow logic to service classes.

## Pilot States And Resume Rules

Persisted pilot states:

- `planned`
- `staging_rehearsal_pending`
- `staging_rehearsal_passed`
- `promotion_pending`
- `promotion_applied`
- `secret_rebind_pending`
- `source_verified`
- `initial_sync_completed`
- `candidate_built`
- `qa_ready`
- `signoff_completed`
- `publish_pending`
- `published`
- `first_pull_verified`
- `feedback_review_active`
- `hypercare_active`
- `completed`
- `blocked`
- `failed`
- `aborted`

How to interpret them:

- `blocked`: an operator step is required. Read the blocker code and next-step guidance from `pilot:status` or the Pilot Center.
- `failed`: the system step failed; use `pilot:resume` only after fixing the concrete cause.
- `aborted`: execution is intentionally stopped; evidence and history remain downloadable.

Common blocker classes:

- promotion drift
- secret rebind missing
- sign-off missing
- publish window blocked
- critical conformance errors
- smoke failure
- first-pull failure

Resume safety:

- only the persisted `resume.retry_state` and `resume.step` are considered safe retry points
- publish, smoke and first-pull are never silently replayed by guessing
- blocked/failed state keeps operator-visible next steps in the run summary

## First Real Merchant Pilot Runbook

Recommended end-to-end path for a real merchant:

1. Select the merchant feed profile and create a pilot run with `pilot:plan` or `/admin/pilot-runs`.
2. Run staging rehearsal and review the generated rehearsal summary, preview URL and QA bundle.
3. Run promotion dry-run, then apply production-safe config promotion.
4. Rebind and validate target source secrets when the run enters `secret_rebind_pending`.
5. Run source sync and build the candidate generation.
6. Review QA, preview and sign-off readiness.
7. Publish from the pilot flow or continue after a deliberate manual publish pause.
8. Confirm smoke check and first-pull verification.
9. Import merchant feedback, work the remediation queue, and clear backlog.
10. Keep hypercare active until the merchant is stable enough for closeout.
11. Generate the evidence pack and archive it with the merchant launch notes.

This workflow is designed to prove a practical merchant launch path, not merely a one-off dry feature demo.

## Pilot Evidence Pack And Reports

Generate with:

```bash
php artisan pilot:evidence {pilotRunId}
```

Or download from `/admin/pilot-runs/{pilotRunId}/evidence`.

The evidence ZIP includes:

- execution summary
- state history
- readiness summary
- rehearsal summary
- promotion summary
- secret rebind status
- source verification result
- candidate generation summary
- preview / QA / sign-off summary
- publish summary
- smoke check result
- first-pull verification result
- feedback import summary
- remediation summary
- hypercare summary
- links / checksums
- operator notes
- incident summary
- HTML index and JSON files

Also downloadable from pilot details:

- summary report
- blocker report
- execution log
- readiness report

## Pilot Fixture Library And Proof Testing

Golden proof fixtures live in `database/samples/pilot`.

Contents:

- `sources/prom_yml`
- `sources/prom_api`
- `kasta-dictionaries`
- `feedback`
- `expected`

Use them when you need deterministic pilot verification without calling live external services. The feature/integration suite covers:

- `prom_yml` happy path
- `prom_api` happy path
- promotion + secret rebind pending path
- publish + smoke + first-pull path
- feedback import / remediation / hypercare path

## What To Collect As Proof Of Success

For a real merchant pilot, retain:

1. pilot run ID and final state
2. rehearsal result
3. promotion dry-run/apply summary
4. secret rebind validation result
5. source sync result
6. candidate checksum and preview / QA references
7. sign-off result
8. publish summary
9. smoke-check result
10. first-pull verification result
11. feedback import and remediation summary
12. hypercare status or closeout report
13. Pilot Evidence Pack ZIP

## Live Merchant Launch Record

Use `/admin/merchant-launches` to create one persisted production launch record for the first real merchant rollout after the pilot is complete.

Each launch stores:

- linked shop / feed profile
- linked pilot run, promotion run and published generation
- environment and launch owner
- planned start, actual publish time and actual go-live confirmation time
- current launch state, notes, summary and outcome

Launch states:

- `planned`
- `executing`
- `published`
- `validating`
- `degraded`
- `stabilized`
- `rolled_back`
- `failed`
- `closed`

This record is intentionally separate from the pilot run because it captures production facts, not only pre-launch readiness.

## Observations And Post-Launch Defect Triage

The launch detail screen is the operator checklist for the first live run.

Observation types cover:

- merchant confirmation
- first marketplace pickup confirmed
- unexpected rejection pattern
- feed delay observed
- image or content issue trend
- mapping issue discovered
- performance issue
- false alarm

Structured defects classify post-launch issues as:

- `data_quality`
- `mapping_gap`
- `source_sync_issue`
- `export_conformance_issue`
- `feedback_matching_issue`
- `performance_issue`
- `ops_issue`
- `false_positive`

Each defect also stores severity, status and optional links to the feed item, feedback record, generation, alert and originating observation.

Quick operator actions from the launch screen:

- open remediation workbench
- open mappings
- exclude item
- rebuild candidate
- rerun smoke or first-pull verification
- import feedback
- rollback with reason

## Baseline Vs Actual And Handover

Every launch stores an expected band for:

- ready items
- published count
- first-pull latency
- feedback volume
- rejection volume
- sync freshness

The launch screen and `launch:check` show:

- actual vs expected
- deltas and in-range / warning / out-of-range status
- critical blockers
- next actions
- open incidents and open defects

Handover is blocked while any critical blocker remains, including deploy verification gaps, publish failures, smoke or first-pull failures, critical alerts, critical launch defects, unacceptable baseline deviations or missing merchant confirmation.

## Post-Launch Tuning Rules

Tuning is allowed only through safe existing settings paths. Supported tuning actions are:

- publish guards
- merchant overrides
- excluded categories or vendors
- minimum image count
- minimum price
- forced attribute overrides
- forced value overrides

Every tuning action records:

- actor
- reason
- mode: `normal` or `emergency`
- before / after settings snapshot

Use tuning to localize and mitigate real launch defects, not as an untracked shortcut around validation or release rules.

## Launch Reports And Closeout

Downloadable launch artifacts:

- launch summary report
- observation report
- defect report
- closeout report

The closeout report is the handover artifact for steady-state operations and should summarize what happened, what was fixed, what remains risky and what follow-up is recommended.

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

## Hypercare War Room

Use `/admin/feed-profiles/{profile}/hypercare` immediately after the first live publish.

This screen is the operator war room for the first `24h` / `72h` and shows:

- current hypercare state
- current risk state
- time since publish and planned end
- next required checks
- blocking incidents and all open alerts
- latest smoke / first-pull / sync status
- feedback SLA summary and grouped rejections
- readiness / SLO / queue status
- active silence window details
- recent live timeline events

Primary actions from the war room:

- rerun smoke check
- rerun first-pull verification
- import feedback
- open remediation workbench
- acknowledge / resolve / false-positive alerts
- extend, close or abort hypercare
- freeze / unfreeze the feed
- rollback
- add manual operator notes
- start or clear a silence window

## Hypercare States And Closeout Rules

Persisted states:

- `planned`
- `armed`
- `active`
- `degraded`
- `extended`
- `completed`
- `aborted`

Operational rules:

- `feed:hypercare:start` can open a manual window before the first publish
- successful live publish auto-activates hypercare when auto-start is enabled
- critical incidents immediately move the open window into `degraded`
- unresolved critical incidents block clean closeout
- `feed:hypercare:close` stores a markdown closeout report in the runbooks directory

## Post-Launch Monitoring Policies

Policies are evaluated against the current profile and hypercare window. Results are persisted as `ok`, `warning`, or `critical`.

Checks covered:

- smoke check cadence
- first-pull verification cadence
- source sync freshness
- publish delta anomaly
- broken source auth
- rejection spike
- ready-items drop
- queue backlog / failed jobs
- feed URL latency
- unresolved feedback backlog

Cadence is phase-aware:

- first `24h`
- first `72h`
- steady state after `72h`

Use per-profile overrides only when the merchant needs different operational thresholds than the global defaults in `config/feed_mediator.php`.

## Alerts / Escalation / Silence

Alert severities:

- `info`
- `warning`
- `critical`

Alert states:

- `raised`
- `acknowledged`
- `silenced`
- `escalated`
- `resolved`
- `false_positive`

Escalation:

- run `php artisan ops:alerts:review` from the scheduler or on demand
- raised alerts escalate after `FEED_MEDIATOR_ALERT_ESCALATE_MINUTES` if nobody acknowledged them
- critical alerts always remain visible and also degrade hypercare

Silence windows:

- are profile-scoped
- store `active_from`, `active_to`, severity threshold, note and actor
- silence only lower-severity noise; critical alerts still persist normally
- can be started from the war room or via `ops:silence`
- can be cleared from the war room or via `ops:silence:clear`

## Unified Live Timeline

Timeline screen:

- `/admin/feed-profiles/{profile}/hypercare/timeline`

The unified timeline combines:

- sync/build/publish logs
- release actions and overrides
- smoke checks
- first-pull verifications
- alert raise/acknowledge/resolve/escalate events
- feedback remediation notes
- freeze toggles
- rollback
- relevant deploy / restore-drill / rehearsal / secret-rotation ops runs

Filters:

- event type
- severity
- date range

Downloads:

- CSV from the timeline page for incident review or handoff

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

The incident journal is now a unified layer rather than one release-only log.

Persistence used by the operator workflow:

- `ops_alerts` for alert/incident state
- `feed_release_events` for audit and manual operator actions
- `sync_logs` for structured runtime log events
- `ops_runs` for deploy, rehearsal, restore-drill and secret-rotation events

Use `/admin/feed-profiles/{profile}/hypercare/timeline` as the main operator-facing incident history. The release center audit table still remains the best focused view for publish/rollback/sign-off actions.

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

## Feedback SLA / Rejection Follow-Up

During hypercare, track not only the rejection count but the reaction speed.

The feedback SLA summary includes:

- unmatched feedback count
- open rejected items
- in-progress remediation
- fixed
- `wont_fix`
- excluded
- average time to acknowledge
- average time to resolve
- grouped rejection reasons
- per-day reason trends
- unresolved backlog

Use the war room links to jump directly from backlog or rejection spikes into the remediation workbench.

Warning signs:

- rejection spike after publish or republish
- unresolved backlog growing across handoffs
- slow acknowledge / resolve times despite stable sync/build signals

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

## Daily Digest And Shift Handoff

Admin:

- `/admin/feed-profiles/{profile}/hypercare/digest`
- `/admin/feed-profiles/{profile}/hypercare/handoff`

CLI:

- `php artisan ops:digest {feedProfileId} --date=YYYY-MM-DD`
- `php artisan ops:handoff {feedProfileId}`

Daily digest covers:

- sync / build / publish summary
- smoke / first-pull summary
- alert summary
- feedback / rejection summary
- unresolved blockers
- recent manual actions

Shift handoff covers:

- current hypercare status
- open incidents
- pending actions
- next checks due
- stability score and recommended next steps

Reports are generated as Markdown and can be downloaded directly from admin.

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

## Merchant Promotion Workflow

Use `/admin/feed-profiles/{profile}/promotion` when a merchant is configured in staging and must be moved into production safely.

Practical operator path:

1. generate a staging snapshot
2. download the JSON artifact
3. import it in the production profile
4. run compare
5. run dry-run with the intended strategy
6. apply the promotion
7. rebind and validate target source secrets
8. continue with release center, acceptance, cutover and hypercare

Snapshot contents:

- shop non-secret config
- onboarding/bootstrap state
- feed settings and release rules
- mappings and merchant overrides
- dictionary references/checksums
- source driver metadata and non-secret options
- compatibility metadata and fingerprints

Excluded intentionally:

- raw API tokens
- plaintext credentials
- transient runtime-only status fields

## Dry-Run / Apply Strategies

- `safe_merge`: update compatible config while preserving unrelated target settings
- `overwrite_target`: make the target match the snapshot, including removal of target-only mappings when safe
- `skip_existing_conflicts`: keep conflicting target rows untouched and report them as skipped

Always review:

- created count
- updated count
- deleted count when `overwrite_target` is used
- skipped count
- conflicts
- warnings
- blocking errors

## Source Secret Rebinding

Promotion never copies secrets as plaintext.

Target source-connection workflow:

1. apply promotion metadata
2. open the target source connection screen
3. re-enter the production token or credentials
4. run `Test connection`
5. confirm the secret state moved from `missing` or `not_validated` to `validated`

If secrets are already present on target, promotion preserves them instead of overwriting them.

## Promotion Rollback Limits

Rollback is config-level only.

- it uses the stored pre-apply target snapshot
- it is safe only while the target still matches the original post-apply snapshot
- it is blocked when the target has drifted since the apply run
- it does not undo runtime events outside the promoted config scope

## Practical Runbook For Moving A Merchant From Staging To Production

1. Finish staging mappings, merchant overrides and release rules.
2. Generate a staging snapshot right before handoff.
3. Import the snapshot in production and review drift plus compatibility.
4. Run dry-run and resolve blocking conflicts first.
5. Apply the promotion.
6. Re-enter production secrets and validate the source connection.
7. Run a fresh source sync if the target catalog needs parity.
8. Open release center / acceptance / runbook / launch pack and confirm promotion status is visible there.
9. Publish only after promotion parity and secret rebind status are acceptable.

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

Hypercare stability score adds launch-specific factors on top of those summaries:

- sync / build / publish success rates
- smoke / first-pull success rates
- feedback rejection volume
- unresolved backlog
- open critical incidents
- rollback during the window

Result states:

- `stable`
- `watch`
- `degraded`
- `unstable`

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

## Practical First 24h / 72h Operator Runbook After Go-Live

First `24h`:

1. Start the launch record with `launch:start` or `/admin/merchant-launches` as soon as the live execution window begins.
2. Keep the launch detail screen and hypercare war room open together.
3. Confirm publish, smoke check, first-pull verification and source sync freshness from the launch checklist.
4. Record merchant confirmation, pickup confirmation and anomalies as observations instead of keeping them only in chat or ad-hoc notes.
5. Run `launch:check {launchId}` after each meaningful operator action so blockers and next actions stay explicit.
6. Import merchant feedback quickly, open structured defects for real issues, and use tuning only with a recorded reason.
7. Use rollback only with an explicit reason and after understanding the incident details.

First `72h`:

1. Use the daily digest before the first shift and the handoff report before operator changeover.
2. Watch rejection spikes, ready-item drops, sync freshness and publish deltas after each remediation publish.
3. Keep observation and defect triage current so handover decisions are based on persisted evidence.
4. Start a silence window only for planned maintenance and only at the needed severity threshold.
5. Extend hypercare when stability is still `watch`, `degraded`, or `unstable`.
6. Hand over and close the launch only after critical blockers are cleared, the stabilization checklist passes and remaining risks are recorded.
