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
    FeedbackImportService.php
    FeedbackRemediationWorkbenchService.php
    FeedSignoffService.php
    FeedSmokeCheckService.php
    KastaExportConformanceService.php
    KastaExportFieldNormalizer.php
    KastaExportXmlService.php
  Services/Ops/
    BackupService.php
    BenchmarkService.php
    OpsMaintenanceStatusService.php
    OpsRunService.php
    ProductionPreflightService.php
    PruneService.php
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
4. `FeedSmokeCheckService` runs generic post-publish checks
5. `FeedFirstPullVerificationService` records the first production pull result
6. the merchant sends acceptance/rejection feedback manually as CSV or JSON
7. `FeedbackImportService` matches that feedback to feed items / source variants
8. `FeedbackRemediationWorkbenchService` groups external rejection reasons into actionable operator queues
9. the operator rebuilds and republishes intentionally until the cutover reaches `pilot_stable`

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
