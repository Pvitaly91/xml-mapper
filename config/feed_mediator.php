<?php

return [
    'storage_disk' => env('FEED_MEDIATOR_STORAGE_DISK', 'local'),
    'imports_directory' => env('FEED_MEDIATOR_IMPORTS_DIRECTORY', 'imports/prom'),
    'builds_directory' => env('FEED_MEDIATOR_BUILDS_DIRECTORY', 'feeds/builds'),
    'published_directory' => env('FEED_MEDIATOR_PUBLISHED_DIRECTORY', 'feeds/published'),
    'health_cache_key' => env('FEED_MEDIATOR_HEALTH_CACHE_KEY', 'feed-mediator:health'),
    'normalization' => [
        'article_keys' => ['article', 'vendorcode', 'vendor_code', 'артикул', 'sku'],
        'size_keys' => ['size', 'розмір', 'размер'],
        'color_keys' => ['color', 'колір', 'цвет'],
    ],
];
