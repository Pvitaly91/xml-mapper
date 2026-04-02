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

`SourceConnection` -> `SourceDriverRegistry` -> driver-specific import -> cached raw snapshot -> driver-specific feed-data loader -> `ProductNormalizer` -> normalized tables -> `ValidationService` -> `FeedBuildService` -> `FeedGeneration` build file -> `FeedPublishService` -> stable `/feeds/{token}.xml`

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

## Background Orchestration

Scheduled commands:

- `source:sync --due --queue`
- `feed:build --due --publish --queue`
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

- a newer built generation exists than the published one
- the published file is missing
- the feed has never been published

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
