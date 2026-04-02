# XML Mapper

Laravel 12 service that mediates between Prom source feeds and Kasta output feeds.

The service keeps Prom as the source of truth, normalizes source data into local tables, validates exportability, builds cached XML generations, and publishes a stable public feed URL.

## Current Scope

- Laravel 12, PHP 8.2+
- MySQL-ready schema
- Redis/queue-friendly jobs and commands
- server-rendered admin UI with Blade
- multi-shop data model
- cached build/publish pipeline
- Kasta dictionary import stubs and commands

## Implemented Modules

- source import, parsing and normalization
- category, attribute and value mapping services
- validation service
- feed build and publish services
- jobs and artisan commands
- admin auth and protected `/admin/*` workflow
- admin CRUD for source connections and feed profiles
- admin workflow for category mappings, attribute mappings, value mappings, feed items
- admin dashboard and dictionary browser
- Kasta dictionary import/reimport workflow

## Local Setup

1. Install dependencies:

```bash
composer install
```

2. Prepare environment:

```bash
copy .env.example .env
php artisan key:generate
```

3. Configure database and queue/cache in `.env`.

4. Run migrations:

```bash
php artisan migrate
```

5. Import Kasta dictionary stubs:

```bash
php artisan kasta:import-dictionaries
```

6. Create a local admin user and shop:

```bash
php artisan admin:bootstrap
```

The command prints the email and password for the admin login.

7. Start the app:

```bash
php artisan serve
```

Admin login is available at `/admin/login`.

## Admin Workflow

### Dashboard

Shows:

- total source products
- total source variants
- total feed items
- ready / invalid / excluded counts
- last sync
- last build
- last publish
- active validation errors

### Source Connections

Available workflow:

- index/create/edit/show
- driver, source URL/path, sync interval, status, credentials JSON, settings JSON
- manual `Sync now`
- last sync status, `last_synced_at`, `next_sync_at`

Manual sync runs the same import + parse + normalize pipeline used by the backend services.

### Feed Profiles

Available workflow:

- index/create/edit/show
- activate / deactivate
- manual build
- manual publish latest generation
- public feed URL visibility
- `include_unavailable`, `auto_sync`, `auto_build`, `build_interval_minutes`

### Category Mappings

Available workflow:

- filter by unmapped / mapped
- filter by strategy (`rz_id`, `manual`)
- filter by active / inactive
- search by source category
- search by Kasta category
- manual create / update / delete / deactivate
- bulk automap by `rz_id`
- full `Run automap`

### Attribute Mappings

Available workflow:

- scoped by feed profile and source category
- manual create / update / delete
- visibility of required Kasta attributes
- separate list of unmapped required attributes
- exact normalized-name suggestion workflow

### Value Mappings

Available workflow:

- scoped by attribute mapping
- manual create / update / delete
- exact normalized-value suggestions
- approve selected suggestions

### Feed Items

Available workflow:

- filter by status
- filter by enabled / disabled
- filter by source category / mapped category
- filter by vendor / article / validation code
- free-text search by product name / offer id / article
- detail page with source snapshots, mapped data and active validation errors
- bulk enable / disable / include / exclude / revalidate
- manual override per item
- rebuild feed from the same profile screen

## CLI Operations

### Source Sync

```bash
php artisan source:sync {sourceConnectionId}
php artisan source:sync --due
php artisan source:sync --queue
```

### Feed Build

```bash
php artisan feed:build {feedProfileId}
php artisan feed:build --due
php artisan feed:build --publish
php artisan feed:build --queue
```

### Feed Publish

```bash
php artisan feed:publish {feedProfileId}
php artisan feed:publish {feedProfileId} --generation=123
php artisan feed:publish {feedProfileId} --queue
```

### Kasta Dictionaries

```bash
php artisan kasta:import-dictionaries
php artisan kasta:reimport-dictionaries
php artisan db:seed --class=Database\\Seeders\\KastaDictionarySeeder
```

Detailed import contract: [docs/kasta-dictionary-import.md](docs/kasta-dictionary-import.md)

## Kasta Dictionary Import Contract

Default import path:

```text
database/data/kasta
```

Expected files:

- `categories.json`
- `attributes.json`
- `attribute_values.json`
- `size_grids.json`

The JSON contract is documented in [docs/kasta-dictionary-import.md](docs/kasta-dictionary-import.md).

## Public Feed

Published feeds are served from:

```text
/feeds/{public_token}.xml
```

The controller serves only already-built and already-published cached XML files. No on-the-fly rendering is used.

## Tests

Run the full suite:

```bash
php artisan test
```

The suite covers:

- admin auth protection
- source connection CRUD and manual sync
- feed profile CRUD and manual build/publish
- category automap workflow
- attribute/value mapping CRUD and suggestions
- feed item manual workflow
- dictionary import/seed workflow
- public feed endpoint regression coverage
