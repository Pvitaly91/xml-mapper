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
- first-live hypercare windows with persisted monitoring state per feed profile
- operator alerts/incidents with acknowledge, resolve, false-positive and escalation flow
- unified live timeline, daily digest, shift handoff and silence windows
- staging-to-production promotion snapshots, drift detection, dry-run/apply, secret-safe rebinding and config rollback history
- persisted pilot execution workflow with operator state/history, evidence pack export, readiness score, pilot center and fixture-backed proof tests
- live merchant launch records with baseline-vs-actual tracking, observation/defect triage, safe tuning history, stabilization handover and closeout reports
- outbound notification routing with delivery history, correlation IDs, suppression/escalation rules, channel tests and admin Notification Center
- Playwright-based browser E2E harness with reproducible demo bootstrap data, CI artifacts, and critical security/governance/release coverage
- production-scale catalog readiness with deterministic scale bootstrap fixtures, persisted performance runs, budget evaluation, chunked report paths, and admin Performance Center

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

## First Real Merchant Pilot Execution

The system now has a dedicated persisted pilot run layer for the real merchant path:

`merchant selected -> staging rehearsal -> promotion dry-run -> production config apply -> secret rebind -> sync -> candidate build -> QA/sign-off -> publish -> smoke -> first-pull -> feedback import -> remediation -> hypercare evidence/closeout`

Operator entry point:

- `/admin/pilot-runs`

What a pilot run persists:

- owner / initiator
- environment context
- shop / feed profile / source snapshot / generation references
- current state and current step
- started / finished timestamps
- blocker reason, note, summary and meta
- step history, operator notes, incidents and overrides

Pilot states:

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

Failure / resume rules:

- `blocked`: operator action is required, the run keeps its evidence and exposes the next safe step plus blocker code.
- `failed`: a system step failed; the run can be resumed only from the explicitly stored retry state.
- `aborted`: execution stops intentionally; history and downloadable evidence stay available.
- safe resume is stateful, not ad hoc: the run stores `resume.allowed`, `resume.retry_state`, `resume.step`, safe retry steps and reset-sensitive areas.

Admin Pilot Center actions:

- plan a run
- execute next step
- resume blocked/failed run
- abort with reason
- add note / incident / override
- open rehearsal / promotion / release center / feedback remediation / hypercare
- download evidence pack and pilot reports

## Pilot Commands

```bash
php artisan pilot:plan {feedProfileId} --note="..."
php artisan pilot:run {feedProfileId} --with-sync --with-build --with-publish --with-feedback-fixtures
php artisan pilot:resume {pilotRunId} --with-sync --with-build --with-publish --with-feedback-fixtures
php artisan pilot:abort {pilotRunId} --reason="..."
php artisan pilot:evidence {pilotRunId}
php artisan pilot:status {pilotRunId}
```

Command output is JSON-first so operators can archive or script the result.

## Pilot Evidence Pack

`pilot:evidence` and `/admin/pilot-runs/{id}/evidence` generate one ZIP bundle per run.

Included artifacts:

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
- `index.html` plus JSON payloads and the candidate XML when available

Pilot reports are also downloadable separately from the Pilot Center:

- summary report
- blocker report
- execution log
- readiness report

## Pilot Fixture Library

Reproducible proof fixtures live under:

- `database/samples/pilot/sources/prom_yml`
- `database/samples/pilot/sources/prom_api`
- `database/samples/pilot/kasta-dictionaries`
- `database/samples/pilot/feedback`
- `database/samples/pilot/expected`

The fixture library covers:

- sample `prom_yml` feed
- paginated `prom_api` responses
- Kasta dictionary inputs
- feedback CSV and JSON
- expected mapping snapshots
- expected XML fragments
- expected publish / smoke / first-pull summaries

Use these fixtures for integration-proof runs and regression-safe pilot verification. Tests do not call real external services.

## Operator Proof Checklist

For a real merchant pilot, collect and retain:

1. pilot run ID and final state from `/admin/pilot-runs`
2. rehearsal result
3. promotion dry-run/apply summary
4. secret rebind validation evidence
5. source sync result
6. candidate checksum and preview/QA bundle references
7. sign-off result
8. publish summary
9. smoke-check result
10. first-pull verification result
11. feedback import and remediation summary
12. hypercare status or closeout report
13. the generated Pilot Evidence Pack ZIP

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

## First Real Merchant Production Execution

For the first real merchant go-live the operator workflow now extends beyond candidate acceptance and pilot execution:

1. verify the production deploy run in ops
2. execute and finish the persisted pilot run
3. create one live merchant launch record
4. confirm the first real publish and go-live observations
5. compare baseline vs actual live metrics
6. capture launch defects and apply safe tuning when needed
7. stabilize, hand over, and close the launch with evidence

New operator surfaces:

- `/admin/merchant-launches`
- `/admin/merchant-launches/{launch}`
- `/admin/feed-profiles/{profile}/operations`
- `/admin/feed-profiles/{profile}/reconciliation`
- `/admin/feed-profiles/{profile}/feedback/import`
- `/admin/feed-profiles/{profile}/feedback-workbench`

New commands:

```bash
php artisan feed:cutover-status {feedProfileId}
php artisan feed:first-pull-verify {feedProfileId} --generation={generationId}
php artisan feed:hypercare:start {feedProfileId} --hours=24 --note="launch window"
php artisan feed:hypercare:status {feedProfileId}
php artisan feed:hypercare:close {feedProfileId} --reason="merchant stable"
php artisan feedback:import csv --file=/abs/path/feedback.csv --feed-profile={feedProfileId} --dry-run
php artisan feedback:reconcile {feedProfileId}
php artisan ops:alerts:review --profile={feedProfileId}
php artisan ops:digest {feedProfileId} --date=2026-04-03
php artisan ops:handoff {feedProfileId}
php artisan ops:silence {feedProfileId} --from="2026-04-03 23:00" --to="2026-04-04 01:00" --severity=warning --reason="planned maintenance"
php artisan ops:silence:clear {feedProfileId}
php artisan feed:runbook {feedProfileId}
php artisan launch:start {feedProfileId} --pilot={pilotRunId} --promotion={promotionRunId} --note="first live merchant launch"
php artisan launch:status {launchId}
php artisan launch:observe {launchId} merchant_confirmation --severity=medium --note="merchant confirmed live visibility"
php artisan launch:defect {launchId} mapping_gap --severity=high --note="live mapping issue"
php artisan launch:check {launchId}
php artisan launch:handover {launchId} --reason="stable after first live window"
php artisan launch:close {launchId} --reason="closeout complete"
```

`feedback:import` assumes `--feed-profile` is provided because feedback is matched against one shop/feed-profile context. This keeps manual CSV/JSON imports deterministic and shop-scoped.

## Live Merchant Launch Record

`/admin/merchant-launches` persists one production launch artifact per real merchant rollout. Each launch stores:

- linked feed profile, pilot run, promotion run and published generation
- owner / initiator and environment context
- planned start, actual publish time and go-live confirmation time
- current launch state, summary, notes and outcome

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

The launch record is separate from the pilot run on purpose: pilot proves readiness, launch proves what happened in production.

## Launch Observations, Defects And Tuning

The live launch screen is the operator checklist for the first real publish. It centralizes:

- baseline vs actual launch metrics
- observations such as merchant confirmation, pickup confirmation, rejection spikes, feed delay and false alarms
- structured post-launch defects with links to feed items, feedback records, alerts and observations
- safe tuning actions that reuse existing feed-profile settings and always record actor, reason and mode (`normal` vs `emergency`)

Quick actions from the launch screen include:

- add observation
- open defect
- rerun smoke
- rerun first-pull verification
- import feedback
- open remediation
- rebuild candidate
- rollback
- hand over or close the launch

## Baseline, Handover And Proof

Every launch records an expected band for:

- ready items
- published count
- first-pull latency
- feedback / rejection volume
- sync freshness

The screen and `launch:check` show actual vs expected, deltas, critical blockers, open incidents and next actions. Handover stays blocked while critical blockers remain, including smoke or first-pull failures, critical alerts/defects, unacceptable launch baselines, or missing merchant confirmation.

Downloadable launch artifacts include:

- summary report
- observation report
- defect report
- closeout report

## First 24h After Real Launch

Recommended operator path for the first live merchant:

1. start the launch record from `/admin/merchant-launches` or `launch:start`
2. keep `/admin/merchant-launches/{launch}` open as the execution checklist
3. record merchant confirmation and any real anomalies as observations
4. run `launch:check {launchId}` after each significant event or mitigation
5. import feedback early, triage defects quickly, and use tuning only with an explicit reason
6. hand over only after the stabilization checklist passes
7. close the launch only when closeout notes and remaining risks are recorded

## First-Pull Verification

First-pull verification is separate from generic smoke checks and is cutover-specific.

It stores:

- status
- verified timestamp
- latency
- response size
- offers/categories totals
- checksum summary
- warnings and errors

It is triggered:

- automatically right after publish
- manually from the acceptance screen or operations screen
- from CLI with `feed:first-pull-verify`

Use it to confirm the first production fetch after go-live, even if the ordinary smoke check already passed.

## Reconciliation Report

`/admin/feed-profiles/{profile}/reconciliation` compares:

- total source products / variants
- normalized variants
- mapped items
- ready items
- excluded items
- invalid items and blocker categories
- latest published offer count
- source vs published deltas

Download formats:

- JSON
- CSV

This report is intended for operator triage when the merchant asks why source counts and published counts diverge.

## Manual Feedback Import

The system does not assume any official Kasta rejection API. Merchant feedback is handled through a manual import pipeline.

Supported formats:

- CSV
- JSON

Supported identifiers:

- `offer_id`
- `external_item_reference`
- `vendor_code`
- `article`

Stored per record:

- acceptance status: `accepted`, `rejected`, `warning`, `unknown`
- rejection reason code and message
- matched feed item / source variant when found
- raw payload
- remediation status

Use the import page for dry-run preview before persisting records.

## Rejection Remediation Workflow

`/admin/feed-profiles/{profile}/feedback-workbench` groups imported feedback into an operator queue.

Problem filters:

- unmatched feedback
- missing mapping
- content issues
- image issues
- pricing issues
- size/color issues

Resolution statuses:

- `open`
- `in_progress`
- `fixed`
- `wont_fix`
- `excluded`

Quick actions link the operator back to feed item diagnostics, mappings and candidate rebuild.

## Merchant-Specific Overrides

Feed profile export settings now support merchant overrides:

- excluded source categories
- excluded vendors / brands
- minimum price threshold
- override minimum pictures
- disabled mapped Kasta categories
- forced attribute overrides JSON
- forced value overrides JSON

These settings remain profile-scoped inside `feed_profiles.settings` and are applied by the shared validation/conformance layer instead of ad-hoc Blade rules.

## Production Operations Screen

`/admin/feed-profiles/{profile}/operations` is the day-to-day production screen for one merchant.

It shows:

- last sync / build / publish / preview
- current cutover state
- latest smoke check and first-pull verification
- publish window and freeze state
- broken source auth signal
- relevant failed jobs count
- merchant feedback summary
- recent incidents and warnings

Use it as the main control point between first publish and pilot stabilization.

## First Live Merchant Hypercare Workflow

The first real launch now opens a dedicated hypercare layer for the specific shop/feed profile and, when available, the currently published generation.

Persisted hypercare states:

- `planned`
- `armed`
- `active`
- `degraded`
- `extended`
- `completed`
- `aborted`

Persisted window attributes:

- `started_at`
- `planned_end_at`
- `actual_end_at`
- owner and initiating operator
- escalation level
- operator note
- target SLA minutes
- monitoring cadence minutes

Runtime behavior:

- manual start is available from `/admin/feed-profiles/{profile}/hypercare` or `feed:hypercare:start`
- hypercare auto-activates after a successful live publish when `FEED_MEDIATOR_HYPERCARE_AUTO_START_ON_PUBLISH=true`
- critical alerts automatically degrade the current hypercare window
- clean closeout is blocked while unresolved critical incidents still exist
- closeout writes a markdown summary into the runbooks storage tree

## Hypercare Policy Layer

The post-launch monitoring layer reuses the existing smoke-check, first-pull, ops and SLO services instead of duplicating independent checks.

Policy coverage:

- smoke check cadence
- first-pull verification cadence
- source sync freshness
- publish delta anomaly
- broken source auth
- feedback rejection spike
- ready-items drop
- failed jobs / queue backlog
- feed URL latency
- unresolved feedback backlog

Policy results are persisted per feed profile / hypercare window as:

- `ok`
- `warning`
- `critical`

Thresholds come from `config/feed_mediator.php` and can be overridden per feed profile through hypercare policy settings stored on the profile.

Phase-aware cadence:

- first `24h`
- first `72h`
- steady state beyond `72h`

## Hypercare War Room

The operator war room lives at:

- `/admin/feed-profiles/{profile}/hypercare`

It shows:

- current hypercare state and risk state
- time since publish and planned end
- next required checks
- open warning/critical alerts
- latest smoke, first-pull and source sync status
- feedback SLA summary and grouped rejection reasons
- release readiness and SLO summary
- queue backlog and failed jobs relevant to the merchant
- active silence window details
- recent live timeline events

Available live actions:

- rerun smoke check
- rerun first-pull verification
- import feedback
- open remediation workbench
- acknowledge / resolve / false-positive alerts
- freeze or unfreeze the feed
- rollback
- extend hypercare
- close or abort hypercare
- add operator notes
- start or clear a silence window

## Alerting And Escalation

Operator alerts are now persisted and linked to the shop/feed profile/hypercare context.

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

Alert sources currently covered:

- source auth broken
- sync failure
- build failure
- publish failure
- smoke check failure
- first-pull verification failure
- feedback rejection spike
- published count delta anomaly
- ready-items collapse
- queue backlog issue

Delivery and persistence:

- database state in `ops_alerts`
- structured log trail in `sync_logs`
- release/audit event trail in `feed_release_events`
- optional mail notifications when `FEED_MEDIATOR_ALERT_MAIL_ENABLED=true`

Escalation:

- `ops:alerts:review` escalates unacknowledged raised alerts after `FEED_MEDIATOR_ALERT_ESCALATE_MINUTES`
- critical alerts immediately mark the current hypercare window as degraded
- operators can acknowledge, resolve or mark false-positive directly from the admin war room

## Unified Live Timeline

Live timeline screen:

- `/admin/feed-profiles/{profile}/hypercare/timeline`

The timeline aggregates one merchant feed-profile stream across:

- release and publish actions
- smoke checks
- first-pull verifications
- sync/build/publish logs
- rollback and freeze toggles
- feedback-related remediation notes
- alert raise/acknowledge/resolve/escalate events
- rehearsal, restore-drill, deploy and secret-rotation ops runs
- manual operator notes

The screen supports:

- event-type filters
- severity filters
- date-range filters
- CSV download for handoff or incident reporting

## Feedback SLA And Rejection Follow-Up

The manual feedback path remains file-driven and does not assume any Kasta rejection API.

Hypercare feedback SLA summary tracks:

- unmatched feedback count
- open rejected items
- in-progress remediation
- fixed
- `wont_fix`
- excluded
- average time to acknowledge
- average time to resolve
- grouped rejection reasons
- rejection reason trends
- unresolved backlog

Backlog and spike conditions feed the hypercare alert layer so the operator sees them alongside publish and platform incidents.

## Stability Score And Closeout

`FeedStabilityService` evaluates hypercare readiness using:

- sync success rate
- build success rate
- publish success rate
- smoke success rate
- first-pull success rate
- feedback rejection volume
- unresolved backlog
- open critical incidents
- rollback occurrence during the window

Result states:

- `stable`
- `watch`
- `degraded`
- `unstable`

Hypercare closeout:

- cannot complete cleanly while critical incidents are still unresolved
- writes a markdown closeout report with timeline summary, incidents, resolutions, remaining risks and recommended next steps
- is accessible from the war room and `feed:hypercare:close`

## Daily Digest And Shift Handoff

Daily digest and shift handoff reports are available both in admin and via artisan.

Admin:

- `/admin/feed-profiles/{profile}/hypercare/digest`
- `/admin/feed-profiles/{profile}/hypercare/handoff`

CLI:

- `php artisan ops:digest {feedProfileId} --date=YYYY-MM-DD`
- `php artisan ops:handoff {feedProfileId}`

Generated markdown reports summarize:

- sync/build/publish activity
- smoke and first-pull status
- open alerts and blockers
- feedback/rejection backlog
- recent manual actions
- current hypercare status
- pending actions
- next checks due

## Silence Windows

Planned maintenance can temporarily suppress lower-severity noise per feed profile.

Supported silence window fields:

- `active_from`
- `active_to`
- severity threshold
- note / reason
- creating operator

Behavior:

- critical alerts are never discarded
- alerts at or below the selected severity threshold can be stored as `silenced`
- the active silence window is visible in the war room, audit trail and timeline

Commands:

- `php artisan ops:silence {feedProfileId} --from= --to= --severity=warning --reason="..."`
- `php artisan ops:silence:clear {feedProfileId}`

## Practical First 24h / 72h Runbook After Go-Live

First `24h`:

1. Publish the approved generation and confirm hypercare moved to `active`.
2. Open `/admin/feed-profiles/{profile}/hypercare`.
3. Confirm the automatic smoke check and first-pull verification completed.
4. Watch source sync freshness, queue backlog, alert state and feedback imports at the war-room cadence.
5. Acknowledge or resolve alerts explicitly instead of relying on log-only visibility.
6. Import merchant feedback, triage open rejections and keep the remediation workbench moving.

First `72h`:

1. Keep reviewing the daily digest and shift handoff before operator changes.
2. Watch publish delta, ready-item drop and rejection trends after each remedial rebuild/publish.
3. Use silence windows only for planned maintenance, never to hide critical launch issues.
4. Extend hypercare if the stability state is `watch`, `degraded` or `unstable`.
5. Close hypercare only after critical incidents are resolved and the stability score is acceptable.

## Runbook Export

`feed:runbook` and `/admin/feed-profiles/{profile}/runbook` generate a merchant-specific Markdown checklist snapshot.

The runbook includes:

- source verification
- dictionaries state
- mappings review
- candidate / QA bundle / sign-off state
- publish window and freeze state
- publish execution
- first-pull verification
- feedback import follow-up

The latest runbook snapshot is also stored on the current cutover meta.

## Notification Channels And Delivery Center

Use `/admin/notifications` as the operator-facing outbound notification screen.

Supported outbound channels:

- `database`
- `log`
- `email`
- `webhook`

What the center provides:

- recent outbound deliveries with filters by channel, status, severity, event type, feed profile and date range
- route management at `global`, `shop` and `feed_profile` scope
- test delivery actions for persisted routes or one-off targets
- retry for safe failed deliveries
- channel health visibility through last delivery, last successful test and last failed test

Webhook URLs and other sensitive target fragments are redacted in UI and persisted response metadata.

## Routing, Suppression And Escalation

Notification routes are stored in `ops_notification_routes`.

Each route can define:

- channel and target
- scope: `global`, `shop`, or `feed_profile`
- `event_family`, `event_type` and minimum severity
- per-channel enable/disable
- `muted_until`
- quiet hours start/end/timezone
- delivery policy overrides for suppression window, repeat interval, escalation delay, timeout and retry attempts

Event families currently covered include:

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

Outbound delivery state is stored in `ops_notification_deliveries` with:

- `pending_delivery`
- `delivered`
- `acknowledged`
- `suppressed`
- `escalated`
- `resolved`
- `dropped`
- `failed`

Duplicate alert noise is controlled through dedup keys, suppression windows and repeat intervals. Suppressed deliveries are still persisted, so operators can see why a message did not fan out. Unacknowledged alerts can escalate through the same routing layer instead of creating a separate incident-notification subsystem.

## Correlation IDs And Error Tracking

Every HTTP request receives a correlation ID through `X-Correlation-ID` by default. The ID is propagated into:

- queued jobs
- alerts/incidents
- outbound deliveries
- publish, smoke and first-pull artifacts
- launch and hypercare events
- structured log context

This makes it possible to trace one operator action or runtime failure across admin requests, jobs, logs, incidents and external notifications.

Optional external error tracking is supported through config only:

```dotenv
FEED_MEDIATOR_ERROR_TRACKING_DRIVER=sentry
FEED_MEDIATOR_ERROR_TRACKING_DSN=
```

If no DSN is configured, the hook is a no-op. Secrets and token-like fields are redacted before structured logging or optional error-tracker capture.

## Launch And Hypercare Outbound Propagation

Live launch and hypercare screens now show recent outbound deliveries plus failed, suppressed and escalated counts.

Important events generate outbound notification candidates, including:

- launch degraded
- smoke failed
- first-pull failed
- rejection spike
- rollback executed
- critical incident still unresolved

Hypercare closeout and launch closeout reports include outbound notification summaries when those deliveries are relevant to the operational story.

## Notification Commands And Retention

Operator and scheduler commands:

```bash
php artisan ops:notify:test {channel} --target= --shop=
php artisan ops:notify:retry {deliveryId}
php artisan ops:alerts:dispatch-pending
php artisan ops:alerts:escalate-due --shop= --profile=
php artisan ops:deliveries:prune
php artisan ops:channels:status --shop=
```

Recommended operator check before a live launch:

1. Open `/admin/notifications`.
2. Send a test notification to each active external route.
3. Confirm `last_test_succeeded_at` moved and the recorded delivery reached `delivered`.
4. If a delivery fails, inspect the delivery detail and retry only after fixing the target or route policy.

Retention for outbound delivery history is controlled by `FEED_MEDIATOR_NOTIFY_RET_DAYS`; `ops:deliveries:prune` is scheduled automatically and keeps the notification journal from growing without bound.

## Access Control And Governance

Admin access is now governed through persisted `shop_memberships` instead of assuming every `admin` user can operate everywhere.

Supported roles:

- `platform_admin`
- `shop_admin`
- `operator`
- `reviewer`
- `observer`

Key rules:

- one user can hold different roles in different shops
- `platform_admin` can access every active shop
- `shop_admin` is limited to assigned shops but can manage onboarding, source, mappings, release, promotion, pilot, launch, hypercare and compliance inside that scope
- `operator` can execute day-to-day operational workflows but cannot approve governance requests
- `reviewer` can inspect workflows, review approvals and open compliance history without taking sensitive operational actions
- `observer` stays read-only

Use `/admin/access` as the Access Center for memberships, approvals and compliance history. The current shop selector is RBAC-aware and refuses shop switches outside the user's active memberships.

## Authentication, MFA And Session Governance

The admin surface now adds a security layer on top of RBAC and approvals.

Account lifecycle:

- internal invites create a real `admin_invites` record plus membership in `invited` state
- users move through `invited`, `active`, `suspended`, `locked`, `password_reset_required`
- invite acceptance sets the initial password, activates the membership and records auth audit
- revoked or suspended membership does not regain legacy fallback access
- globally suspended users cannot sign in even if a membership is still active

MFA / TOTP:

- TOTP enrollment is available from the admin security flow
- the setup screen shows the authenticator secret and provisioning URI; QR SVG is rendered when the optional QR package is installed
- recovery codes are shown once, stored encrypted+hashed metadata style, and cannot be reused
- `platform_admin` MFA is required by policy in production by default
- MFA reset is operator-controlled, session-revoking and audited

Step-up auth for dangerous actions:

- dangerous actions no longer rely only on role or approval
- the governed action layer now also checks recent password confirmation
- policy can require recent MFA for selected action families or high-risk production actions
- result states are explicit: `allowed`, `password_reauth_required`, `mfa_reauth_required`, `blocked_by_policy`

Session governance:

- active admin sessions are persisted with IP, device label, last seen time, MFA verification time and break-glass state
- operators can inspect sessions, revoke one session, revoke other sessions, or revoke all sessions from Access Center
- password change revokes other sessions by default and this behavior is configurable

Break-glass:

- break-glass is temporary session-scoped emergency mode for `platform_admin` only
- a reason is required
- recent re-auth is required first
- TTL is enforced and expiry is audited
- it is visible in the admin shell and auth audit trail; it is not a hidden bypass user

Auth audit / compliance:

- invite created / resent / revoked / accepted
- login success / failure
- account lock / unlock
- password reset required / password changed
- MFA enrolled / verified / failed / reset
- session revoked / reuse blocked
- re-auth challenged / succeeded / failed
- break-glass started / ended

Use `/admin/access/auth-audit` for filtered auth event review and `php artisan auth:audit:report` for JSON export.

## Secure Operator Workflow

Recommended production operator pattern:

1. accept access through an internal invite instead of sharing credentials.
2. complete password setup and MFA enrollment before opening live release or source-secret screens.
3. keep day-to-day work shop-scoped; reserve `platform_admin` for cross-shop governance.
4. when a dangerous action asks for recent auth, complete password/MFA re-auth instead of bypassing it.
5. use break-glass only for explicit emergencies and record a clear reason.
6. review auth audit and session state during post-incident or compliance follow-up.

## Dangerous Actions And Approval Queue

High-risk actions no longer execute directly from admin screens.

Governed actions currently include:

- force publish
- rollback
- freeze toggle
- promotion apply
- promotion rollback
- secret rebind and secret rotation confirmation
- emergency tuning
- launch closeout override
- critical alert silence window creation
- destructive prune / maintenance

Approval requests are stored in `approval_requests` with:

- `pending`
- `approved`
- `rejected`
- `expired`
- `cancelled`
- `executed`

Policy enforcement is centralized and environment-aware:

- staging can execute some sensitive actions directly
- production can require approval for the same action
- `high_risk` actions can require a `4-eyes` reviewer
- platform-only actions such as destructive prune stay blocked for non-platform roles

When approval is required, the original action payload is stored with the request and executed from that persisted payload after approval. This prevents an operator from approving one thing and then submitting a different form body.

CLI helpers:

```bash
php artisan access:list-members --shop=
php artisan access:grant {user} {role} --shop= --by=
php artisan access:revoke {user} {role} --shop= --by=
php artisan approval:list --status=pending
php artisan approval:approve {approvalId} --note= --by=
php artisan approval:reject {approvalId} --note= --by=
php artisan compliance:report --shop= --user= --from= --to=
```

`approval:approve` and `approval:reject` require an explicit `--by` actor so approval execution stays attributable in audit history.

## Secret Governance And Compliance Trail

Source credentials and other sensitive values remain encrypted at rest and masked by default in admin screens.

Operational rules:

- secret values are never echoed back into edit forms
- source connection screens show only masked or confirm-only state
- secret reveal is not the default operator path; operators confirm or rotate instead
- secret update, rebind and rotation events are audited
- production secret changes can require approval through the same governed action layer
- flash messages, logs, notification payloads and compliance summaries redact secret-like fragments

Governance-grade audit history is stored in `governance_audits`.

Use `/admin/access/compliance` to filter:

- actions by user, date and shop
- approvals history
- sensitive action attempts
- launch / release / promotion governance history

Compliance exports include correlation IDs when available, making it possible to trace one sensitive production action across admin request, queue work, notification delivery and downstream incident handling.

## Production Basics

- use Redis for `CACHE_STORE` and `QUEUE_CONNECTION`
- run at least one queue worker listening to `imports,normalization,feeds,dictionaries`
- run the scheduler every minute via cron, or use `schedule:work` under systemd
- monitor `/health`
- inspect `failed_jobs` and clear/retry intentionally

Deploy artifacts:

- cron example: [`deploy/cron/schedule-run.cron`](deploy/cron/schedule-run.cron)
- supervisor worker config: [`deploy/supervisor/xml-mapper-worker.conf`](deploy/supervisor/xml-mapper-worker.conf)
- supervisor scheduler config: [`deploy/supervisor/xml-mapper-scheduler.conf`](deploy/supervisor/xml-mapper-scheduler.conf)
- systemd worker unit: [`deploy/systemd/xml-mapper-worker.service`](deploy/systemd/xml-mapper-worker.service)
- systemd scheduler unit: [`deploy/systemd/xml-mapper-schedule-work.service`](deploy/systemd/xml-mapper-schedule-work.service)
- nginx vhost: [`deploy/nginx/xml-mapper.conf`](deploy/nginx/xml-mapper.conf)
- PHP-FPM pool example: [`deploy/php-fpm/www.conf.example`](deploy/php-fpm/www.conf.example)
- release-based deploy script: [`scripts/deploy.sh`](scripts/deploy.sh)
- rollback script: [`scripts/rollback.sh`](scripts/rollback.sh)

## Production Deploy Topology

Recommended server layout:

- `nginx` terminates HTTPS and forwards PHP requests to `php-fpm`
- `php-fpm` serves Blade admin, public feed XML and CLI commands
- `MySQL` stores all catalog, mapping, release and ops state
- `Redis` backs cache, locks and queues
- queue workers consume `imports,normalization,feeds,dictionaries`
- scheduler runs `schedule:run` via cron or `schedule:work` under systemd/supervisor
- feed artifacts, preview bundles, backups and runbooks stay on the configured Laravel storage disk

Release layout expected by the deploy scripts:

- `/var/www/xml-mapper/releases/<timestamp-sha>`
- `/var/www/xml-mapper/shared/.env`
- `/var/www/xml-mapper/shared/storage`
- `/var/www/xml-mapper/current -> releases/<active>`

## Zero-Downtime Deploy And Rollback

Server-side deploy:

```bash
APP_BASE=/var/www/xml-mapper \
DEPLOY_REPO_URL=git@github.com:Pvitaly91/xml-mapper.git \
DEPLOY_BRANCH=main \
APP_URL=https://xml-mapper.example.com \
bash scripts/deploy.sh
```

What the deploy script does:

1. clones the selected branch into a new release directory
2. links shared `storage` and shared `.env`
3. installs production Composer dependencies
4. runs `php artisan migrate --force`
5. rebuilds Laravel caches
6. switches the `current` symlink
7. restarts queue workers with `queue:restart`
8. runs `ops:preflight-production`
9. hits `/health`
10. optionally runs `feed:smoke-check` if `FEED_MEDIATOR_DEPLOY_SMOKE_FEED_PROFILE_ID` is configured
11. records deploy metadata via `ops:record-deploy`

Rollback:

```bash
APP_BASE=/var/www/xml-mapper \
APP_URL=https://xml-mapper.example.com \
bash scripts/rollback.sh
```

Important limitation:

- code rollback is safe and automated
- database rollback is not automatic
- if a destructive migration shipped, database restore is a manual restore-from-backup operation and must be treated explicitly in the runbook

## Production Preflight

Run before and after deploy:

```bash
php artisan ops:preflight-production
```

The command checks:

- DB connection
- Redis connection
- required tables/schema readiness
- writable storage directories
- queue readiness
- scheduler heartbeat hints
- `APP_KEY`
- environment sanity (`APP_ENV`, `APP_DEBUG`)
- critical config keys

Each run is persisted in `ops_runs` and surfaced on the admin dashboard and profile operations screen.

## Backup, Restore And Retention

Commands:

```bash
php artisan ops:backup-db
php artisan ops:backup-files
php artisan ops:prune
```

Backups:

- DB backup creates an SQL dump on the configured storage disk
- files backup creates a ZIP archive with builds, published feeds, imports, feedback artifacts, dictionaries and runbooks
- every run is persisted in `ops_runs` with path, size and status

Restore notes:

- database restore: import the saved SQL dump into MySQL or SQLite before bringing workers back
- file restore: unpack the ZIP onto the shared storage disk, then rerun `ops:preflight-production`

Retention currently prunes:

- old non-published generation XML artifacts
- expired/revoked preview links
- old smoke-check history while keeping the latest row per generation
- old feedback import source files
- old QA bundle ZIPs
- old runbook snapshots
- old preflight/benchmark `ops_runs`

Retention is configurable through `.env` knobs such as `FEED_MEDIATOR_RET_*`.

## Scale Bootstrap And Performance Center

Large-catalog readiness is now exercised through deterministic scale fixtures plus persisted benchmark history instead of one-off ad hoc timing notes.

Bootstrap commands:

```bash
php artisan ops:load-bootstrap --fresh --products=5000 --variants-per-product=4 --label="5k catalog"
php artisan demo:bootstrap-scale --fresh --products=10000 --variants-per-product=5 --label="10k rehearsal"
```

What bootstrap provisions:

- dedicated scale shop and feed profile
- reproducible Prom YML catalog fixture on local storage
- source categories, products, variants, images and attributes through the normal sync/normalize path
- initial mappings and a built candidate generation
- feedback CSV and JSON samples for the same dataset

The dataset is deterministic for the same size inputs, so repeated runs are comparable and do not rely on random demo data.

Admin entry point:

- `/admin/performance`

The Performance Center shows:

- recent load/bootstrap and benchmark runs
- run status, dataset size, duration and peak memory
- per-stage budget result
- regression comparison against the previous run
- links back to the related shop and feed profile
- downloadable JSON performance report for each run

## Performance Runs And Budgets

Persisted performance history lives in:

- `performance_runs`
- `performance_run_stages`

Recorded scope:

- run type
- shop / feed profile / operator
- dataset size
- executed stages
- status
- duration
- peak memory
- processed products / variants / rows
- warnings / errors / report counts
- environment label and notes

Benchmark command:

```bash
php artisan ops:benchmark-run {feedProfileId} --stages=sync,normalize,build,reconciliation,report_generation --label="local smoke"
php artisan ops:benchmark-compare --profile={feedProfileId}
php artisan ops:queue-health
php artisan ops:report-heavy-queries
php artisan ops:prune-performance-runs
```

Budget result states:

- `within_budget`
- `warning`
- `critical`

Budget coverage currently includes:

- sync
- normalize
- build
- publish
- smoke
- reconciliation
- report generation
- feedback import
- queue lag

When a run or stage becomes `critical`, the result is surfaced in the dashboard, operations screens, hypercare/launch snapshots, Performance Center, and ops alerts.

## Large-Catalog Hardening Notes

Scale hardening added in this step:

- explicit indexes for feed items, feedback records, and notification deliveries on proven list/filter hotspots
- chunked/streamed export generation for invalid-item reports, QA bundle invalid CSVs, compliance exports, pilot execution log CSVs, and launch observation/defect CSVs
- deterministic stress-oriented tests for duplicate build/sync suppression and idempotent queue paths
- persisted regression comparison between recent performance runs instead of vague “it felt slower” operator judgment

## Large Merchant Readiness Checklist

Before the first large-catalog go-live, operator and dev should verify:

1. scale bootstrap finished for a dataset close enough to the target merchant size
2. the same feed profile passed at least one persisted benchmark run across sync, normalize, build and report-heavy stages
3. budget results are not `critical`, and any `warning` stages are understood
4. queue health and backlog stay inside expected thresholds
5. reconciliation, diff, compliance and invalid-item exports still complete through the chunked paths
6. launch and hypercare screens do not show unresolved performance degradation signals
7. comparison against the previous run does not show an unexplained heavy regression

## Security Hardening Notes

HTTP protections now include:

- `X-Frame-Options`
- `X-Content-Type-Options`
- `Referrer-Policy`
- conservative CSP for HTML admin pages
- HSTS on secure requests

Rate limiting:

- admin login: `throttle:admin-login`
- release/ops-sensitive POST actions: `throttle:admin-sensitive`

Secret handling:

- Prom API tokens stay encrypted in the database
- `api_token` is excluded from flashed validation input
- preview and release-sensitive actions remain auth + shop-scoped
- token rotation should be handled by updating the source connection and rerunning `source:test`

## Daily / Weekly Ops Checklist

Daily:

1. open `/admin`
2. confirm ops status, last preflight and backup timestamps
3. check failed jobs and queue backlog
4. review broken Prom API auth indicators
5. review retention warnings and run `ops:prune` if needed

Weekly:

1. run `ops:preflight-production`
2. verify DB and files backups can be downloaded
3. run `ops:benchmark-feed` for the busiest profile
4. review storage growth and stale preview/build artifacts
5. rotate any stale source credentials or tokens deliberately

Detailed ops runbook: [docs/operations.md](docs/operations.md)

## Staging vs Production Separation

The service now exposes an explicit environment layer through `FEED_MEDIATOR_ENV_CLASS`.

- `local`: development-only
- `staging`: rehearsal / canary / restore-drill environment
- `production`: live merchant environment

The current environment is shown as a badge in the admin shell, dashboard, release center, acceptance screen, operations screen, and rehearsal screen.

Recommended staging baseline:

- `APP_ENV=staging`
- `APP_DEBUG=false`
- `FEED_MEDIATOR_ENV_CLASS=staging`
- separate DB / Redis / shared storage / app URL from production

## Staging-To-Production Promotion Workflow

Use the Promotion Center at `/admin/feed-profiles/{profile}/promotion` to move a merchant from staging-ready config into production without copying secrets unsafely.

Operator flow:

1. generate a promotion snapshot on staging
2. download the snapshot JSON
3. import that snapshot in production for the target merchant/profile
4. run `compare` to see drift and compatibility
5. run `dry-run` with the intended strategy
6. apply only after the dry-run is clean enough
7. re-enter and validate any source secrets on production
8. continue with release center / acceptance / cutover / hypercare

CLI:

```bash
php artisan promotion:snapshot {feedProfileId} --env=staging
php artisan promotion:diff {sourceFeedProfileId} {targetFeedProfileId} --source-env=staging --target-env=production
php artisan promotion:dry-run {sourceFeedProfileId} {targetFeedProfileId} --strategy=safe_merge
php artisan promotion:apply {sourceFeedProfileId} {targetFeedProfileId} --strategy=safe_merge --reason="approved staging config"
php artisan promotion:rollback {promotionRunId} --reason="revert target config"
```

## Promotion Snapshot Contents

A promotion snapshot contains only promotion-relevant, non-secret configuration:

- shop-level non-secret config
- onboarding/bootstrap state
- feed profile config and export settings
- category / attribute / value mappings
- merchant overrides
- publish guards, freeze mode and publish-window rules
- dictionary references and checksums
- source connection driver metadata and non-secret options
- compatibility metadata and fingerprints

It never includes raw API tokens or plaintext credentials.

## Drift Detection And Strategies

Drift compare classifies the target as:

- `no_drift`
- `drift_detected`
- `incompatible`

Dry-run/apply strategies:

- `safe_merge`: update compatible config and mappings, preserve unrelated target settings
- `overwrite_target`: replace conflicting target config and delete target-only mappings when needed to match the source snapshot
- `skip_existing_conflicts`: keep target conflicts in place and skip those rows explicitly

The dry-run summary shows what will be created, updated, deleted, skipped, or blocked before any write happens.

## Source Secret Rebinding After Promotion

Source connection promotion rules are intentionally secret-safe:

- driver metadata and non-secret fields move with the snapshot
- existing production tokens are preserved
- missing target secrets are marked as `missing`
- re-entered but untested secrets are marked as `not_validated`
- only a successful source connection test marks them as `validated`

Production operators should open the source connection screen after apply, paste the target secret, run `Test connection`, and only then treat promotion parity as fully clean.

## Promotion Rollback Limits

Promotion rollback is config-level only.

- it uses the pre-apply target snapshot as the rollback baseline
- it can safely restore settings and mappings when the target has not drifted since the apply run
- it is blocked when the current target config no longer matches the original post-apply snapshot
- it is not a universal database rollback and does not undo runtime side effects outside the promoted config scope

## Practical Merchant Move From Staging To Production

1. Finish merchant setup, mappings and release rules in staging.
2. Run a fresh staging snapshot and archive the JSON artifact with the release notes.
3. Import the snapshot into production and check `drift`, `promotion needed`, and `secret rebind pending`.
4. Run dry-run and review create/update/delete/conflict results with the merchant owner or release operator.
5. Apply the promotion, then immediately rebind source secrets if needed.
6. Run source `Test connection`, sync, and production readiness checks.
7. Open release center / acceptance / runbook / launch pack and confirm promotion parity is now visible there.
8. Proceed with the normal production launch and then hypercare.

## Staging Rehearsal Workflow

Admin path:

- `/admin/feed-profiles/{profile}/rehearsal`

CLI:

```bash
php artisan feed:rehearse-launch {feedProfileId} --with-sync --with-build --with-preview --with-smoke --with-rollback-check
```

Persisted rehearsal status:

- `not_started`
- `in_progress`
- `passed`
- `failed`
- `blocked`

Each run is stored in `ops_runs` with step details, warnings, blocking issues, QA bundle reference, canary preview link, smoke result, and rollback rehearsal result.

## Canary / Safe Publish Rehearsal

Rehearsal publish does not touch the stable public feed URL.

- it uses the existing preview-link layer with `meta.target=canary`
- smoke checks can run directly against that canary artifact
- first-pull verification can run against that canary artifact
- rollback rehearsal can verify a fallback artifact for the currently published generation through another isolated preview link

This keeps the existing public feed endpoint unchanged while still giving the operator a realistic publish/smoke/rollback drill.

## Restore Drill

Restore drill is checklist-based and non-destructive.

- run it from the feed-profile operations screen
- every run is persisted in `ops_runs` with type `restore_drill`
- a markdown report is written to shared storage

The drill verifies:

- latest DB backup exists
- latest files backup exists
- shared storage contains the published artifact if one exists
- current ops health is reachable

## Secret Rotation Notes

Secret values are never stored in the repo or in rotation history. Only metadata is persisted.

- Prom API token presence and last validation time are visible on the source connection details screen
- token rotation metadata can be recorded from `/admin/source-connections/{connection}`
- rotation history is stored in `ops_runs` with type `secret_rotation`

Optional extra hardening:

```dotenv
FEED_MEDIATOR_REQUIRE_HIGH_RISK_CONFIRMATION=true
```

When enabled, force publish, rollback, freeze toggles, and non-dry-run feedback import require `confirmation=CONFIRM` in addition to the existing reason field.

## Reliability Summary

The dashboard and operations screen expose rolling `24h` and `7d` operator summaries for:

- sync success rate
- build success rate
- publish success rate
- smoke-check success rate
- first-pull verification success rate

Overall state is summarized as:

- `healthy`
- `warning`
- `degraded`

## First Merchant Launch Pack

Generate from:

- `/admin/feed-profiles/{profile}/launch-pack`

The generated markdown pack includes:

- shop and source summary
- dictionary and unresolved mapping state
- candidate generation readiness
- sign-off state
- publish window / freeze status
- preview / QA references
- cutover / rollback / first-pull plan
- feedback import plan
- operator notes

## Public Feed

Published feeds are served from:

```text
/feeds/{public_token}.xml
```

The endpoint serves only already-built and already-published files. No runtime XML rendering happens in the public controller.

## Browser E2E And Demo QA

Playwright is the browser E2E stack for this repository. It fits the current Blade/session architecture cleanly, keeps controllers thin, and gives stable HTML reports plus screenshots, traces, and videos on failure.

Install browser-test dependencies once:

```bash
npm install
npm run e2e:install
```

Prepare reproducible demo data for browser tests or manual operator walkthroughs:

```bash
php artisan demo:bootstrap-e2e --fresh
```

Useful local commands:

```bash
npm run e2e:smoke
npm run e2e
npm run e2e:report
php artisan demo:bootstrap-e2e --fresh --json
```

The bootstrap command provisions:

- demo main and secondary shops for shop-switch and scoped-RBAC coverage
- platform admin, reviewer, operator, and invited shop-admin accounts
- mapping-ready `prom_yml` source fixtures and a publish-ready feed profile
- local-only manifest with demo credentials and MFA seeds at `storage/app/e2e/demo-manifest.json`
- safe summary without secrets at `storage/app/e2e/demo-summary.json`

Handling rules:

- never upload `storage/app/e2e/demo-manifest.json` to CI artifacts or external reports
- browser artifacts are stored in `playwright-report/` and `test-results/`
- the invite/MFA spec disables screenshot, trace, and video capture to avoid leaking freshly issued MFA or recovery material

Covered browser flows include:

- invite -> accept -> password set -> MFA enrollment -> login
- MFA login challenge
- shop switching under RBAC rules
- source connection create/edit/test under governance constraints
- dangerous actions that require step-up re-auth or approval creation
- reviewer approval queue actions
- release, pilot, launch, notification-center, and session-management happy paths

CI browser workflow:

- `.github/workflows/browser-e2e.yml`
- push / pull request: smoke subset via `npm run e2e:smoke`
- manual dispatch / nightly schedule: full suite via `npm run e2e`
- both `playwright-report/` and `test-results/` are uploaded as CI artifacts

## Manual Pre-Live QA Script

Before a first live merchant launch, run this short manual script on a demo or staging mirror:

1. Run `php artisan demo:bootstrap-e2e --fresh`.
2. Open `/admin/login`, sign in as the invited user from the safe summary, accept the invite, and set the password.
3. Enroll MFA, save the recovery codes locally, log out, and confirm a fresh MFA login challenge works.
4. Confirm current environment, current shop, current role, and any break-glass state are clearly visible in the admin shell.
5. Open onboarding or source connections, edit the demo connection, and run `Test connection`.
6. Build or review the candidate generation and confirm preview / QA bundle visibility.
7. Submit a governed dangerous action and confirm the UI tells the operator whether password re-auth, MFA re-auth, or approval is required.
8. Sign in as reviewer, open the approval queue, and approve or reject the pending request.
9. Open Release Center, Pilot Center, Launch Center, and Notification Center to confirm recent state, alerts, and outbound test-delivery behavior.
10. Open the sessions screen, revoke other sessions, and confirm the action is visible and audited.

## Tests

Run the full suite:

```bash
php artisan test
```
