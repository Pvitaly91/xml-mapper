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

## Runtime Flow

`SourceConnection` -> `SourceDriverRegistry` -> driver-specific import -> cached raw snapshot -> driver-specific feed-data loader -> `ProductNormalizer` -> normalized tables -> `ValidationService` -> `KastaExportConformanceService` -> `FeedItemDiagnosticsService` -> `FeedBuildService` -> `FeedGeneration` build file + diff/guard meta -> `FeedReleaseService` -> `FeedPublishService` -> stable `/feeds/{token}.xml` -> `FeedSmokeCheckService`

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
- `FeedReleaseAuditService` stores manual release actions in `feed_release_events`
- `FeedReleaseReportService` exports invalid-item, diff and readiness reports for operators

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
    FeedGenerationDiffService.php
    FeedItemDiagnosticsService.php
    FeedPilotReadinessService.php
    FeedPublishGuardService.php
    FeedReleaseAuditService.php
    FeedReleaseReadinessService.php
    FeedReleaseReportService.php
    FeedReleaseService.php
    FeedSmokeCheckService.php
    KastaExportConformanceService.php
    KastaExportFieldNormalizer.php
    KastaExportXmlService.php
  Services/Ops/
  Services/Source/
    Drivers/
database/
  data/kasta/                    # legacy local-dev sample bundle
  samples/kasta-dictionaries/    # file-driven sample fixtures
docs/
deploy/
tests/
```
