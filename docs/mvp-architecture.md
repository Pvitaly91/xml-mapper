# Prom -> Kasta Feed Mediator MVP

## Assumptions

1. Один `shop` може мати кілька `source_connections` і кілька `feed_profiles`.
2. На цьому етапі один `feed_profile` працює з одним `source_connection`.
3. `Prom` є єдиним source of truth; сервіс зберігає нормалізовану read model для побудови фіда.
4. `source_products` зберігає нормалізовану картку товару, `source_variants` зберігає SKU/offer-рівень.
5. В actual-стані значення атрибутів SKU зберігаються в `source_variants.attributes_snapshot` JSON, а таблиці `source_attributes` / `source_attribute_values` є словником і mapping anchor.
6. `offer_id` фіксується на рівні `source_variants.stable_offer_id` і не перевираховується після створення запису; нестабільність відловлюється через `published_export_key_hash`.
7. XML-фід не рендериться на льоту: `FeedBuildService` створює build-файл, `FeedPublishService` публікує готовий XML у стабільний `published_path`.
8. Адмінські POST routes на цьому кроці без auth/UI; інтеграційний захист буде додано окремо.

## Flow

`SourceConnection` -> `SourceImportService` -> cached XML file -> `PromYmlParser` -> `ProductNormalizer` -> normalized tables -> `ValidationService` -> `FeedBuildService` -> `FeedGeneration` XML -> `FeedPublishService` -> public `/feeds/{token}.xml`

## Module Structure

```text
app/
  Contracts/
    Feeds/
    Mappings/
    Source/
    Validation/
  Data/Source/
  Http/Controllers/
    Admin/
  Jobs/
  Models/
  Services/
    Feeds/
    Mappings/
    Source/
    Validation/
  Support/
database/
  migrations/
docs/
tests/
  Feature/
  Unit/
```

## ERD

```mermaid
erDiagram
    shops ||--o{ users : has
    shops ||--o{ source_connections : has
    source_connections ||--o{ source_imports : imports
    source_connections ||--o{ source_categories : owns
    source_connections ||--o{ source_products : owns
    source_connections ||--o{ source_variants : owns
    source_connections ||--o{ source_attributes : owns
    shops ||--o{ feed_profiles : has
    feed_profiles ||--o{ feed_items : exports
    feed_profiles ||--o{ feed_generations : builds
    feed_profiles ||--o{ category_mappings : configures
    feed_profiles ||--o{ attribute_mappings : configures
    source_categories ||--o{ source_products : categorizes
    source_products ||--o{ source_variants : has
    source_attributes ||--o{ source_attribute_values : has
    kasta_categories ||--o{ kasta_attributes : has
    kasta_attributes ||--o{ kasta_attribute_values : has
    source_categories ||--o{ category_mappings : maps
    kasta_categories ||--o{ category_mappings : maps
    source_attributes ||--o{ attribute_mappings : maps
    kasta_attributes ||--o{ attribute_mappings : maps
    attribute_mappings ||--o{ value_mappings : maps
    source_attribute_values ||--o{ value_mappings : source
    kasta_attribute_values ||--o{ value_mappings : target
    source_variants ||--o{ feed_items : selected
    source_variants ||--o{ validation_errors : validates
    source_products ||--o{ validation_errors : groups
    feed_items ||--o{ validation_errors : raises
    source_imports ||--o{ sync_logs : writes
    feed_generations ||--o{ sync_logs : writes
```
