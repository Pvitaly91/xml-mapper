# Kasta Dictionary Import Contract

The default import path is:

```text
database/data/kasta
```

The import service accepts four JSON files. Each file must decode into a top-level array.

## `categories.json`

```json
[
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
]
```

Required keys:

- `external_id`
- `name`

Optional keys:

- `parent_external_id`
- `full_path`
- `rz_id`
- `is_active`
- `metadata`

## `attributes.json`

```json
[
  {
    "kasta_category_external_id": "KASTA-TSHIRTS",
    "external_id": "attr-color",
    "name": "Color",
    "code": "color",
    "data_type": "string",
    "is_required": true,
    "allows_custom_value": false,
    "sort_order": 10
  }
]
```

Required keys:

- `kasta_category_external_id`
- `name`
- `code`

Optional keys:

- `external_id`
- `data_type`
- `is_required`
- `allows_custom_value`
- `sort_order`

## `attribute_values.json`

```json
[
  {
    "kasta_category_external_id": "KASTA-TSHIRTS",
    "kasta_attribute_code": "color",
    "external_id": "val-black",
    "value": "Black",
    "sort_order": 10
  }
]
```

Required keys:

- `kasta_category_external_id`
- `kasta_attribute_code`
- `value`

Optional keys:

- `external_id`
- `sort_order`

## `size_grids.json`

```json
[
  {
    "shop_slug": null,
    "code": "adult-alpha",
    "name": "Adult Alpha",
    "schema": {
      "labels": ["XS", "S", "M", "L", "XL"]
    },
    "is_active": true
  }
]
```

Required keys:

- `code`
- `name`

Optional keys:

- `shop_slug`
- `schema`
- `is_active`

When `shop_slug` is omitted or `null`, the imported size grid is global. When it is present, the importer resolves the matching `shops.slug`.

## Commands

```bash
php artisan kasta:import-dictionaries
php artisan kasta:reimport-dictionaries
php artisan db:seed --class=Database\\Seeders\\KastaDictionarySeeder
```

Both artisan commands use idempotent upserts and can be rerun safely. The seeder delegates to the same service.
