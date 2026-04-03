# Prom -> Kasta Feed Mediator Architecture

## Core Assumptions

1. One `shop` can own multiple `source_connections` and multiple `feed_profiles`.
2. One `feed_profile` is bound to one `source_connection`.
3. Prom remains the source of truth.
4. Public XML is never rendered on the fly. The system builds and publishes cached files.
5. Admin remains server-rendered with Blade, session auth and `Gate::define('access-admin')`.
6. Controllers stay thin. Runtime logic lives in actions, services and jobs.
7. Source imports are driver-based and resolved from `source_connections.driver`.
8. Prom API contract uncertainty stays isolated inside the Prom API client/driver layer.
9. Admin access is single-shop scoped per user; shop resolution is centralized and reused across admin actions.
10. New-shop onboarding and go-live daily operations are separate operator flows built on top of the same domain services.

## Runtime Flow

`SourceConnection` -> `SourceDriverRegistry` -> driver-specific import -> cached raw snapshot -> driver-specific feed-data loader -> `ProductNormalizer` -> normalized tables -> `ValidationService` -> `KastaExportConformanceService` -> `FeedItemDiagnosticsService` -> `FeedBuildService` -> `FeedGeneration` build file + diff/guard meta -> `FeedReleaseService` -> `FeedPublishService` -> stable `/feeds/{token}.xml` -> `FeedSmokeCheckService`

Operator flow:

`ShopOnboardingService` -> onboarding wizard -> `BootstrapShopForPilotAction` -> default feed profile + initial mapping suggestions + first candidate -> `ShopControlPanelService` -> unresolved workbench -> release center

Pilot execution flow:

`PilotExecutionService` -> `PilotRunStateMachine` -> persisted `pilot_runs` / `pilot_run_events` -> rehearsal -> promotion -> source verification -> sync -> build -> QA/sign-off -> publish -> smoke / first-pull -> feedback remediation -> hypercare -> evidence pack / reports

Driver paths:

- `prom_yml`:
  - download XML/YML
  - parse via `PromYmlParser`
- `prom_api`:
  - fetch paginated `groups/list`
  - fetch paginated `products/list`
  - cache raw JSON snapshot
  - map Prom payload into the shared parsed feed contract

Prom API connection lifecycle:

- encrypted token stored on `source_connections.api_token`
- `source:test` or admin `Test connection` updates last check status/message
- sync updates last sync status/message/summary
- broken active Prom API auth is surfaced in dashboard and `/health`

Feed-item export lifecycle:

- `ValidationService` handles source-level completeness
- `KastaExportConformanceService` handles Kasta category requirements, value mapping completeness, field normalization, image validity and export-key stability
- `FeedItemDiagnosticsService` resolves the final item status and operator-facing diagnostics payload
- `KastaExportXmlService` renders the offer fragment used by both generation build and item preview
- `FeedGenerationDiffService` compares the built generation with the latest published generation
- `FeedPublishGuardService` blocks publish when profile thresholds or critical conformance rules fail
- `FeedPilotReadinessService` summarizes whether the operator can safely run the first pilot publish
- `FeedReleaseReadinessService` aggregates go-live blockers and warnings before publish
- `FeedReleaseService` owns candidate/approve/publish/force-publish/rollback transitions
- `FeedSmokeCheckService` verifies the published URL after publish or manual rerun
- `FeedCutoverService` tracks the live merchant cutover state for one feed profile and target generation
- `FeedFirstPullVerificationService` persists first production pull verification separately from generic smoke checks
- `FeedReconciliationService` compares source counts, mapped counts, ready counts and published counts
- `FeedbackImportService` imports manual acceptance/rejection CSV or JSON feedback without assuming an external API
- `FeedbackRemediationWorkbenchService` turns imported feedback into an operator remediation queue
- `FeedHypercareService` owns hypercare lifecycle, activation, extension, abort and closeout rules
- `HypercarePolicyService` evaluates phase-aware post-launch monitoring policies and persists per-policy results
- `OpsAlertService` owns alert persistence, silence handling, escalation and operator incident actions
- `FeedLiveTimelineService` unifies release, smoke, first-pull, sync log and ops events into one operator timeline
- `FeedHypercareDashboardService` assembles the live war room view for one merchant/profile
- `FeedHypercareReportService` generates daily digest, shift handoff and closeout markdown reports
- `FeedStabilityService` computes the stability score and closeout posture from launch-time signals
- `FeedbackSlaService` aggregates rejection backlog, acknowledge/resolve timings and reason trends
- `PromotionSnapshotService` builds portable promotion packs with config fingerprints and compatibility metadata
- `PromotionPlannerService` produces drift reports and dry-run/apply plans with create/update/delete/skip/conflict semantics
- `PromotionService` owns compare, dry-run, apply, rollback and promotion audit/history persistence
- `PromotionStatusService` surfaces promotion parity state into readiness, rehearsal, acceptance, release and operations views
- `PromotionReportService` exports markdown/JSON artifacts for runs and snapshots
- `PilotExecutionService` orchestrates one persisted pilot run without duplicating release/promotion/feedback logic
- `PilotRunStateMachine` owns pilot states, steps and next-step labels
- `PilotCenterService` assembles the admin operator view for pilot runs
- `PilotEvidencePackService` exports a ZIP/HTML+JSON proof bundle for one run
- `PilotReportService` exports summary, blocker, execution-log and readiness reports
- `PilotReadinessScoreService` computes `not_ready`, `needs_attention`, `ready`, `stable_after_launch`
- `PilotFixtureLibrary` resolves golden fixtures for proof-grade integration tests
- `FeedOperationsService` aggregates the production operations screen for one profile
- `FeedRunbookService` exports a cutover checklist snapshot
- `FeedReleaseAuditService` stores manual release actions in `feed_release_events`
- `FeedReleaseReportService` exports invalid-item, diff and readiness reports for operators
- `ProductionPreflightService` validates runtime prerequisites before and after deploy
- `BackupService` produces database and files backups onto the configured storage disk
- `PruneService` enforces retention on preview links, smoke checks and old build artifacts
- `BenchmarkService` measures current heavy report paths plus historical sync/build/publish timings
- `OpsMaintenanceStatusService` aggregates backups, deploy metadata, storage usage and queue backlog for admin screens
- `ShopControlPanelService` aggregates daily go-live state for one shop
- `UnresolvedMappingsWorkbenchService` groups blockers into operator queues
- `MappingPresetService` exports/imports reusable mapping presets across similar shops
- `CurrentAdminShopResolver` centralizes shop ownership checks for admin actions

## Background Orchestration

Scheduled commands:

- `source:sync --due --queue`
- `feed:build --due --queue`
- `feed:publish --due --queue`

Queue split:

- `imports`
- `normalization`
- `feeds`
- `dictionaries`

Concurrency and idempotency:

- source sync lock per `source_connection`
- build lock per `feed_profile`
- publish lock per `feed_profile` and `feed_generation`
- dictionary import lock per `{type}:{checksum}`
- scheduler dispatch lock per due subject to avoid duplicate queued jobs on repeated scheduler runs

## Due Resolution

### Source sync

Active source connections where `next_sync_at` is null or expired.

### Feed build

Active feed profiles where `auto_build=true` and `next_build_at` is null or expired.

### Feed publish

Active feed profiles where a publishable generation exists and one of these applies:

- a newer approved generation exists than the published one
- the published file is missing
- the feed has never been published but an approved candidate exists

## Ops Visibility

Heartbeats:

- scheduler heartbeat is recorded from the scheduler itself
- worker heartbeat is recorded from the queue worker loop

Exposed in `/health` and the admin dashboard:

- scheduler heartbeat
- worker heartbeat
- failed jobs count
- broken Prom API auth count
- due source/build/publish counts
- last successful sync/build/publish timestamps
- queue mode

Prom API assumptions:

- `last_id` pagination is treated as descending and inclusive
- `variation_group_id` is the primary grouping key for variants
- documented read payload does not expose arbitrary custom attributes, so normalized source attributes are derived from documented product fields
- if vendor/brand is absent, `options.default_vendor` or the shop name is used as the fallback brand source

Kasta export assumptions:

- stable offer IDs remain authoritative; export conformance does not replace the existing offer ID pipeline
- required attributes come from imported Kasta dictionaries
- missing attribute mapping, missing value mapping and missing source value are tracked separately for operators
- color and size normalization is centralized and reused in diagnostics plus XML preview
- publish readiness depends on generation summary, diff and guard metadata stored on `feed_generations.meta`
- release approval, smoke-check history and audit trail are persisted in dedicated tables to keep operator actions queryable
- candidate preview links are isolated in `feed_generation_preview_links` and never reuse public feed tokens
- release sign-off history is persisted in `feed_generation_signoffs`; publish guard evaluation reads only the current sign-off row
- publish windows and freeze mode stay in `feed_profiles.settings`, so release timing rules remain profile-scoped without a second configuration model
- operator notes reuse `feed_release_events` with `note_added` actions instead of a parallel comments subsystem
- QA bundles are generated on demand from the built generation file plus report services; no extra export model is introduced
- production cutover state is stored in `feed_profile_cutovers` so the operator can track current live-launch progress without inventing a second release engine
- first-pull verification history is stored in `feed_first_pull_verifications` and links back to the smoke-check row that validated the same published URL
- manual merchant feedback imports are stored in `feedback_imports` and `feedback_records`, which keeps external acceptance/rejection history queryable and shop-scoped
- merchant-specific export overrides stay in `feed_profiles.settings`; validation/conformance services read them centrally
- hypercare windows are stored in `feed_hypercare_windows` and stay linked to shop, feed profile and optional live generation
- monitoring policy results are stored in `ops_policy_results`, which keeps cadence/threshold evaluation queryable without scattering ad-hoc status flags
- alerts/incidents are stored in `ops_alerts` and mirrored into `feed_release_events` plus `sync_logs` so operator workflow and forensic logs stay aligned
- maintenance silence windows are stored in `ops_silence_windows` and applied centrally by the alert service instead of in controllers or Blade views
- promotion snapshots are stored in `promotion_snapshots`; promotion history and rollback lineage are stored in `promotion_runs`
- promotion snapshots never carry plaintext source secrets; source-connection promotion metadata tracks `missing`, `not_validated`, and `validated` secret states on the target side

## Dictionary Import Architecture

The dictionary subsystem is file-driven and split into parsing and persistence:

- readers:
  - JSON top-level array reader
  - CSV header-based reader
- type importers:
  - `kasta_categories`
  - `kasta_attributes`
  - `kasta_attribute_values`
  - `size_grids`
- orchestration service:
  - source file storage
  - checksum detection
  - history records in `dictionary_imports`
  - dry-run
  - reimport latest
  - duplicate checksum skip

Admin surface:

- `/admin/dictionaries`
- `/admin/dictionaries/imports`
- `/admin/dictionaries/imports/{id}`
- `/admin/onboarding`
- `/admin/shop/control-panel`
- `/admin/feed-profiles/{profile}/workbench`
- `/admin/feed-profiles/{profile}/mapping-presets/import`

## Module Map

```text
app/
  Actions/Admin/
  Actions/Ops/
  Contracts/Dictionaries/
  Contracts/Feeds/
  Data/Dictionaries/
  Data/Ops/
  Jobs/
  Models/
  Services/Dictionaries/
    Importers/
    Readers/
  Services/Feeds/
    FeedAcceptanceService.php
    FeedCutoverService.php
    FeedFirstPullVerificationService.php
    FeedGenerationDiffService.php
    FeedHypercareDashboardService.php
    FeedHypercareReportService.php
    FeedHypercareService.php
    FeedItemDiagnosticsService.php
    FeedOperationsService.php
    FeedPilotReadinessService.php
    FeedPublishGuardService.php
    FeedPublishWindowService.php
    FeedPreviewLinkService.php
    FeedQaBundleService.php
    FeedReconciliationService.php
    FeedReleaseAuditService.php
    FeedReleaseNotesService.php
    FeedReleaseReadinessService.php
    FeedReleaseReportService.php
    FeedReleaseService.php
    FeedRunbookService.php
    FeedStabilityService.php
    FeedbackImportService.php
    FeedbackSlaService.php
    FeedbackRemediationWorkbenchService.php
    FeedSignoffService.php
    FeedSmokeCheckService.php
    FeedLiveTimelineService.php
    KastaExportConformanceService.php
    KastaExportFieldNormalizer.php
    KastaExportXmlService.php
  Services/Promotion/
    PromotionCenterService.php
    PromotionFingerprintService.php
    PromotionPlannerService.php
    PromotionReportService.php
    PromotionService.php
    PromotionSnapshotService.php
    PromotionStatusService.php
  Services/Pilot/
    PilotCenterService.php
    PilotEvidencePackService.php
    PilotExecutionService.php
    PilotFixtureLibrary.php
    PilotReadinessScoreService.php
    PilotReportService.php
    PilotRunStateMachine.php
  Services/Ops/
    BackupService.php
    BenchmarkService.php
    HypercarePolicyService.php
    OpsMaintenanceStatusService.php
    OpsAlertService.php
    OpsRunService.php
    ProductionPreflightService.php
    PruneService.php
    SilenceWindowService.php
    Services/Admin/
    CurrentAdminShopResolver.php
  Services/Shops/
    MappingPresetService.php
    ShopControlPanelService.php
    ShopOnboardingService.php
    ShopOnboardingStateService.php
    UnresolvedMappingsWorkbenchService.php
  Services/Source/
    Drivers/
database/
  data/kasta/                    # legacy local-dev sample bundle
  samples/kasta-dictionaries/    # file-driven sample fixtures
docs/
deploy/
tests/
```

## Pilot Acceptance Flow

The merchant pilot acceptance path is:

1. onboarding wizard prepares the shop, source and default feed profile
2. build produces a candidate generation file plus summary/diff/guard metadata
3. `FeedPreviewLinkService` issues a signed expiring preview URL for that generation
4. `FeedQaBundleService` packages the XML file and operator reports into a ZIP
5. `FeedSignoffService` records internal/client review state for the generation
6. `FeedPublishWindowService` evaluates publish window and freeze mode
7. `FeedReleaseReadinessService` aggregates source health, mappings, conformance, sign-off, window and ops state
8. `FeedReleaseService` publishes or force-publishes with audit trail and post-publish smoke check

This keeps the whole acceptance workflow generation-centric and reuses the existing build/publish pipeline instead of inventing a parallel release model.

## First Merchant Production Execution Flow

The live merchant execution path is:

1. onboarding and mapping reconciliation prepare a stable candidate generation
2. `FeedCutoverService` starts tracking the merchant launch window and target generation
3. `FeedReleaseService` publishes to the stable public XML URL
4. `FeedHypercareService` activates or arms the hypercare window for that live publish
5. `FeedSmokeCheckService` runs generic post-publish checks
6. `FeedFirstPullVerificationService` records the first production pull result
7. `HypercarePolicyService` evaluates cadence, freshness, queue, latency, delta and rejection policies for the first `24h` and `72h`
8. `OpsAlertService` persists incidents, applies silence windows, escalates overdue alerts and degrades hypercare on critical conditions
9. the merchant sends acceptance/rejection feedback manually as CSV or JSON
10. `FeedbackImportService` matches that feedback to feed items / source variants
11. `FeedbackRemediationWorkbenchService` groups external rejection reasons into actionable operator queues
12. `FeedHypercareDashboardService` exposes the live war room for operators
13. `FeedStabilityService` decides whether the merchant is `stable`, `watch`, `degraded`, or `unstable`
14. the operator rebuilds and republishes intentionally until hypercare can close cleanly

## Pilot Execution State Machine

Persisted pilot state is stored in `pilot_runs`, while operator-visible history is stored in `pilot_run_events`.

States:

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

Operational semantics:

- `blocked` means the system knows the current blocker code and the next safe operator action.
- `failed` means a service/action step threw or returned a hard failure and the run stores an explicit retry state.
- `aborted` preserves history/evidence and intentionally stops the run without pretending a rollback happened.
- terminal states are `completed`, `failed`, and `aborted`.

## Pilot Orchestration Rules

`PilotExecutionService` is a one-run orchestrator, not a duplicate domain module. It reuses:

- `FeedRehearsalService`
- `PromotionService`
- `SourceConnectionTestService`
- `SourceSyncWorkflowService`
- `FeedBuildService`
- `FeedQaBundleService`
- `FeedPreviewLinkService`
- `FeedSignoffService`
- `FeedReleaseService`
- `FeedReleaseReadinessService`
- `FeedFirstPullVerificationService`
- `FeedbackImportService`
- `FeedbackRemediationWorkbenchService`
- `FeedHypercareService`

Resume / retry contract:

- blocked and failed runs store `resume.allowed`, `resume.retry_state`, `resume.step`, `safe_retry_steps`, and reset-sensitive areas.
- publish-window, promotion-drift, secret-rebind, smoke and first-pull blockers remain part of persisted pilot state rather than free-form UI messages.
- the orchestrator can pause intentionally at `publish_pending` when manual publish confirmation is required.

## Pilot Fixture And Proof Layer

Golden fixtures live in `database/samples/pilot` and are accessed only through `PilotFixtureLibrary`.

Fixture groups:

- `sources/prom_yml`
- `sources/prom_api`
- `kasta-dictionaries`
- `feedback`
- `expected`

This keeps high-confidence pilot proof tests deterministic for:

- `prom_yml` merchant flow
- `prom_api` merchant flow
- promotion + secret rebind pending path
- publish + smoke + first-pull path
- feedback import / remediation / hypercare path

## Pilot Evidence And Reporting Layer

`PilotEvidencePackService` produces one ZIP bundle per run with JSON payloads, HTML index and candidate XML when available.

`PilotReportService` exposes smaller operator exports:

- summary
- blockers
- execution-log
- readiness

The evidence layer is intended to answer “did this merchant pilot really complete?” without forcing an operator to manually reconstruct the story from several screens.

## Production Deployment Flow

The production deployment path is intentionally release-based rather than in-place:

1. `scripts/deploy.sh` creates a new release directory
2. shared `.env` and shared `storage` are linked into that release
3. Composer production dependencies are installed inside the release
4. Laravel caches are rebuilt inside the release
5. migrations run with `--force`
6. the `current` symlink switches atomically
7. workers restart via `queue:restart`
8. `ProductionPreflightService` validates runtime state
9. deploy metadata is persisted in `ops_runs`

Rollback is code-level and symlink-based. Database rollback is deliberately not abstracted away as “magic”; restore depends on the backup/restore runbook.

## Staging Rehearsal Flow

1. `EnvironmentContextService` classifies the runtime as `local`, `staging`, or `production`
2. `FeedRehearsalService` opens an `ops_runs` record with type `rehearsal`
3. `ProductionPreflightService` runs against the staging target profile
4. `SourceConnectionTestService` validates the upstream source
5. optional sync/build steps reuse the existing ingestion/build services
6. `FeedPreviewLinkService` creates a canary preview artifact with `meta.target=canary`
7. `FeedSmokeCheckService` and `FeedFirstPullVerificationService` verify that isolated artifact
8. optional rollback rehearsal prepares another isolated preview artifact for the currently published generation
9. the operator sees a persisted rehearsal summary in admin without mutating the stable public feed URL

## Staging-To-Production Promotion Flow

1. `PromotionSnapshotService` captures shop/feed/mapping/override/publish-window config from the staging-side merchant profile
2. the snapshot stores compatibility metadata, dictionary references, source driver metadata and fingerprints, but never raw secrets
3. the operator downloads the snapshot JSON and imports it into the production-side merchant profile
4. `PromotionPlannerService` compares the imported snapshot with the live target profile and emits a `no_drift`, `drift_detected`, or `incompatible` report
5. the planner also builds a dry-run/apply plan using `safe_merge`, `overwrite_target`, or `skip_existing_conflicts`
6. `PromotionService` persists the compare/dry-run/apply run in `promotion_runs`, records audit events, and stores the pre-apply target snapshot for rollback lineage
7. source-connection promotion copies only non-secret metadata; target secrets are preserved or marked for re-entry and validation
8. successful apply updates promotion parity state so the release center, acceptance screen, rehearsal view, operations screen, launch pack and runbook all reflect the latest promotion outcome
9. rollback is config-level only and uses the saved pre-apply target snapshot when the target has not drifted since the apply run

## Reliability / Recovery Layer

- `RestoreDrillService` records non-destructive restore verification drills in `ops_runs`
- `SecretsRotationService` records secret rotation metadata without storing the rotated values
- `SloSummaryService` aggregates rolling 24h / 7d success rates for sync/build/publish/smoke/first-pull
- `FeedLaunchPackService` exports a reusable merchant launch pack from the same acceptance / operations / reconciliation state

## Hypercare / Incident Layer

The first-live merchant workflow extends the existing cutover and release model rather than replacing it:

1. `FeedReleaseService` remains the only publish/rollback orchestrator
2. `FeedHypercareService` opens a scoped window around the live publish
3. `HypercarePolicyService` evaluates phase-aware policies using existing smoke, first-pull, queue, sync and feedback data
4. `OpsAlertService` materializes operator incidents from those policy results and direct runtime failures
5. `FeedLiveTimelineService` merges release events, alerts, sync logs, first-pull checks, smoke checks and relevant `ops_runs`
6. `FeedHypercareReportService` reuses that timeline plus SLA/stability data for digest, handoff and closeout reports

Design choices:

- no fictional Kasta feedback API is introduced; manual CSV/JSON feedback stays the source for rejection follow-up
- controllers stay thin; lifecycle, scoring, alerting, silence and reporting logic live in services
- the public feed endpoint and Prom ingestion paths remain untouched
- audit trail reuse avoids maintaining multiple competing incident journals
- per-profile overrides stay in feed-profile settings so merchant-specific monitoring does not require a second config model

This keeps rehearsal, recovery, and reliability visibility attached to the current architecture instead of inventing a parallel launch subsystem.
