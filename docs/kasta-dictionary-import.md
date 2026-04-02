# Kasta Dictionary Import

The repository does not contain real Kasta export files. It contains sample fixtures and a real file-driven import pipeline.

## Supported Types

- `kasta_categories`
- `kasta_attributes`
- `kasta_attribute_values`
- `size_grids`

## Supported Formats

- `json`
- `csv`

Sample fixtures live in:

```text
database/samples/kasta-dictionaries
```

Legacy local-dev sample bundle remains in:

```text
database/data/kasta
```

## Commands

Import a file:

```bash
php artisan kasta:dictionary:import kasta_categories --file=database/samples/kasta-dictionaries/kasta_categories.json
php artisan kasta:dictionary:import kasta_attributes --file=database/samples/kasta-dictionaries/kasta_attributes.csv --format=csv
```

Dry-run:

```bash
php artisan kasta:dictionary:import kasta_attribute_values --file=database/samples/kasta-dictionaries/kasta_attribute_values.json --dry-run
```

Deactivate missing:

```bash
php artisan kasta:dictionary:import size_grids --file=database/samples/kasta-dictionaries/size_grids.csv --deactivate-missing
```

Reimport the latest stored file for one type:

```bash
php artisan kasta:dictionary:reimport-latest kasta_categories
```

Queue the import on the `dictionaries` queue:

```bash
php artisan kasta:dictionary:import kasta_categories --queue
```

## Admin Workflow

- `/admin/dictionaries/imports`:
  - upload a file or enter a filesystem path
  - choose type and optional format
  - run a dry-run preview
  - enable `deactivate missing`
  - reimport the latest stored file
- `/admin/dictionaries/imports/{id}`:
  - review counts
  - inspect checksum, stored source path and metadata
  - review error summary
  - replay via reimport

Every run is stored in `dictionary_imports`.

## History Fields

Each import stores:

- `type`
- `source_path`
- `original_filename`
- `source_format`
- `checksum`
- `rows_total`
- `created_count`
- `updated_count`
- `skipped_count`
- `deactivated_count`
- `status`
- `started_at`
- `finished_at`
- `error_summary`
- `metadata`
- `initiated_by_user_id`

## Import Semantics

- stable per-row create/update detection
- checksum-based duplicate detection
- dry-run without persisted dictionary writes
- optional `deactivate missing`
- reimport from the latest stored copy
- distributed lock per `{type}:{checksum}`

## Field Mapping

### `kasta_categories`

JSON row:

```json
{
  "external_id": "KASTA-TSHIRTS",
  "parent_external_id": null,
  "name": "T-Shirts",
  "full_path": "Apparel > T-Shirts",
  "rz_id": "2001",
  "is_active": true,
  "metadata": {
    "department": "apparel"
  }
}
```

CSV columns:

- `external_id`
- `parent_external_id`
- `name`
- `full_path`
- `rz_id`
- `is_active`
- `metadata_json`

### `kasta_attributes`

JSON row:

```json
{
  "kasta_category_external_id": "KASTA-TSHIRTS",
  "external_id": "attr-color",
  "name": "Color",
  "code": "color",
  "data_type": "string",
  "is_required": true,
  "allows_custom_value": false,
  "is_active": true,
  "sort_order": 10
}
```

CSV columns:

- `kasta_category_external_id`
- `external_id`
- `name`
- `code`
- `data_type`
- `is_required`
- `allows_custom_value`
- `is_active`
- `sort_order`

### `kasta_attribute_values`

JSON row:

```json
{
  "kasta_category_external_id": "KASTA-TSHIRTS",
  "kasta_attribute_code": "color",
  "external_id": "val-black",
  "value": "Black",
  "is_active": true,
  "sort_order": 10
}
```

CSV columns:

- `kasta_category_external_id`
- `kasta_attribute_code`
- `external_id`
- `value`
- `is_active`
- `sort_order`

### `size_grids`

JSON row:

```json
{
  "shop_slug": null,
  "code": "adult-alpha",
  "name": "Adult Alpha",
  "schema": {
    "labels": ["XS", "S", "M", "L", "XL"]
  },
  "is_active": true
}
```

CSV columns:

- `shop_slug`
- `code`
- `name`
- `schema_json`
- `is_active`

## Replacing Sample Files With Real Kasta Files

1. Export or obtain the real dictionary file from the upstream source.
2. Convert it into one of the supported formats:
   - JSON top-level array of objects
   - CSV with the documented header names
3. Run the correct type-specific command or upload the file from the admin page.
4. Use `--dry-run` first.
5. Enable `--deactivate-missing` only when the file is authoritative for the whole dictionary type.
