<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseSetupInspector
{
    private const ADMIN_REQUIRED_TABLES = [
        'users',
        'shops',
        'source_connections',
        'source_imports',
        'source_categories',
        'source_products',
        'source_variants',
        'source_attributes',
        'source_attribute_values',
        'kasta_categories',
        'kasta_attributes',
        'kasta_attribute_values',
        'size_grids',
        'feed_profiles',
        'category_mappings',
        'attribute_mappings',
        'value_mappings',
        'feed_generations',
        'feed_items',
        'validation_errors',
        'sync_logs',
        'dictionary_imports',
    ];

    private const DASHBOARD_REQUIRED_TABLES = self::ADMIN_REQUIRED_TABLES;

    private const OPS_SOURCE_CONNECTION_TABLES = [
        'source_connections',
    ];

    private const OPS_FEED_BUILD_TABLES = [
        'feed_profiles',
    ];

    private const OPS_FEED_PUBLISH_TABLES = [
        'feed_profiles',
        'feed_generations',
    ];

    private const LAST_SYNC_TABLES = [
        'source_imports',
    ];

    private const LAST_BUILD_TABLES = [
        'feed_generations',
    ];

    private const LAST_PUBLISH_TABLES = [
        'feed_generations',
    ];

    private ?bool $databaseConnected = null;

    /**
     * @var array<string, bool>
     */
    private array $tablePresence = [];

    /**
     * @return array{database_connected:bool,schema_ready:bool,setup_required:bool,required_tables:array<int,string>,missing_tables:array<int,string>}
     */
    public function adminReport(): array
    {
        return $this->report(self::ADMIN_REQUIRED_TABLES);
    }

    /**
     * @return array{database_connected:bool,schema_ready:bool,setup_required:bool,required_tables:array<int,string>,missing_tables:array<int,string>}
     */
    public function dashboardReport(): array
    {
        return $this->report(self::DASHBOARD_REQUIRED_TABLES);
    }

    /**
     * @return array{database_connected:bool,schema_ready:bool,setup_required:bool,required_tables:array<int,string>,missing_tables:array<int,string>}
     */
    public function healthReport(): array
    {
        return $this->report(self::ADMIN_REQUIRED_TABLES);
    }

    /**
     * @return array<int, string>
     */
    public function adminRequiredTables(): array
    {
        return self::ADMIN_REQUIRED_TABLES;
    }

    public function canConnect(): bool
    {
        if ($this->databaseConnected !== null) {
            return $this->databaseConnected;
        }

        try {
            DB::connection()->getPdo();

            return $this->databaseConnected = true;
        } catch (Throwable) {
            return $this->databaseConnected = false;
        }
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public function missingTables(array $tables): array
    {
        if (! $this->canConnect()) {
            return [];
        }

        return array_values(array_filter($tables, fn (string $table): bool => ! $this->hasTable($table)));
    }

    /**
     * @param  array<int, string>  $tables
     */
    public function hasAllTables(array $tables): bool
    {
        return $this->canConnect() && $this->missingTables($tables) === [];
    }

    public function canResolveDueSourceConnections(): bool
    {
        return $this->hasAllTables(self::OPS_SOURCE_CONNECTION_TABLES);
    }

    public function canResolveDueFeedBuilds(): bool
    {
        return $this->hasAllTables(self::OPS_FEED_BUILD_TABLES);
    }

    public function canResolveDueFeedPublishes(): bool
    {
        return $this->hasAllTables(self::OPS_FEED_PUBLISH_TABLES);
    }

    public function canReadLastSuccessfulSync(): bool
    {
        return $this->hasAllTables(self::LAST_SYNC_TABLES);
    }

    public function canReadLastSuccessfulBuild(): bool
    {
        return $this->hasAllTables(self::LAST_BUILD_TABLES);
    }

    public function canReadLastSuccessfulPublish(): bool
    {
        return $this->hasAllTables(self::LAST_PUBLISH_TABLES);
    }

    /**
     * @param  array<int, string>  $tables
     * @return array{database_connected:bool,schema_ready:bool,setup_required:bool,required_tables:array<int,string>,missing_tables:array<int,string>}
     */
    private function report(array $tables): array
    {
        $databaseConnected = $this->canConnect();
        $missingTables = $databaseConnected ? $this->missingTables($tables) : [];

        return [
            'database_connected' => $databaseConnected,
            'schema_ready' => $databaseConnected && $missingTables === [],
            'setup_required' => $databaseConnected && $missingTables !== [],
            'required_tables' => $tables,
            'missing_tables' => $missingTables,
        ];
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tablePresence)) {
            return $this->tablePresence[$table];
        }

        try {
            return $this->tablePresence[$table] = Schema::hasTable($table);
        } catch (Throwable) {
            return $this->tablePresence[$table] = false;
        }
    }
}
