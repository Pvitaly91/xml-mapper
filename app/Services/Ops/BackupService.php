<?php

namespace App\Services\Ops;

use App\Models\OpsRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public function __construct(
        private readonly OpsRunService $opsRunService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function backupDatabase(?User $user = null): array
    {
        $run = $this->opsRunService->start(OpsRun::TYPE_BACKUP_DB, user: $user);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $path = trim(config('feed_mediator.backups.db_directory'), '/')
            .'/db-backup-'.now()->format('YmdHis').'.sql';

        try {
            $sql = $this->dumpSql();
            $disk->put($path, $sql);
            $size = strlen($sql);
            $summary = [
                'driver' => DB::connection()->getDriverName(),
                'tables_total' => count($this->tableNames()),
                'size_bytes' => $size,
            ];

            $run = $this->opsRunService->finish(
                $run,
                OpsRun::STATUS_SUCCEEDED,
                $summary,
                [],
                $path,
                $size,
            );

            return [
                'run' => $run,
                'path' => $path,
                'size_bytes' => $size,
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $this->opsRunService->fail($run, $exception);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function backupFiles(?User $user = null): array
    {
        $run = $this->opsRunService->start(OpsRun::TYPE_BACKUP_FILES, user: $user);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.backups.files_directory'), '/')
            .'/files-backup-'.now()->format('YmdHis').'.zip';
        $absolutePath = $disk->path($relativePath);

        try {
            if (! is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0777, true);
            }

            $zip = new ZipArchive;

            if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create files backup archive.');
            }

            $directories = array_unique(array_filter([
                trim(config('feed_mediator.imports_directory'), '/'),
                trim(config('feed_mediator.published_directory'), '/'),
                trim(config('feed_mediator.builds_directory'), '/'),
                trim(config('feed_mediator.feedback_directory'), '/'),
                trim(config('feed_mediator.runbooks_directory'), '/'),
                trim(config('feed_mediator.kasta_dictionary_storage_directory'), '/'),
            ]));

            $filesTotal = 0;

            foreach ($directories as $directory) {
                foreach ($disk->allFiles($directory) as $file) {
                    $zip->addFile($disk->path($file), $file);
                    $filesTotal++;
                }
            }

            $zip->close();

            $size = filesize($absolutePath) ?: 0;
            $summary = [
                'directories' => $directories,
                'files_total' => $filesTotal,
                'size_bytes' => $size,
            ];

            $run = $this->opsRunService->finish(
                $run,
                OpsRun::STATUS_SUCCEEDED,
                $summary,
                [],
                $relativePath,
                $size,
            );

            return [
                'run' => $run,
                'path' => $relativePath,
                'size_bytes' => $size,
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $this->opsRunService->fail($run, $exception);

            throw $exception;
        }
    }

    private function dumpSql(): string
    {
        $driver = DB::connection()->getDriverName();
        $lines = [
            '-- XML Mapper backup',
            '-- Generated at '.now()->toDateTimeString(),
            '-- Driver: '.$driver,
            '',
        ];

        foreach ($this->tableNames() as $table) {
            $lines[] = '--';
            $lines[] = '-- Table: '.$table;
            $lines[] = '--';
            $lines[] = 'DROP TABLE IF EXISTS '.$this->wrap($table, $driver).';';
            $lines[] = $this->createTableSql($table, $driver).';';

            $columns = Schema::getColumnListing($table);
            $pdo = DB::connection()->getPdo();
            $batch = [];

            foreach (DB::table($table)->cursor() as $row) {
                $values = [];

                foreach ($columns as $column) {
                    $values[] = $this->quoteValue($pdo, $row->{$column} ?? null);
                }

                $batch[] = '('.implode(', ', $values).')';

                if (count($batch) >= 100) {
                    $lines[] = 'INSERT INTO '.$this->wrap($table, $driver)
                        .' ('.$this->wrappedColumns($columns, $driver).') VALUES '.implode(', ', $batch).';';
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $lines[] = 'INSERT INTO '.$this->wrap($table, $driver)
                    .' ('.$this->wrappedColumns($columns, $driver).') VALUES '.implode(', ', $batch).';';
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(DB::select('SHOW TABLES'))
                ->map(static fn (object $row): string => (string) array_values((array) $row)[0])
                ->values()
                ->all();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->values()
                ->all();
        }

        throw new RuntimeException('Database backups are not implemented for the ['.$driver.'] driver.');
    }

    private function createTableSql(string $table, string $driver): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne('SHOW CREATE TABLE '.$this->wrap($table, $driver));

            return (string) ($row->{'Create Table'} ?? '');
        }

        if ($driver === 'sqlite') {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);

            return (string) ($row->sql ?? '');
        }

        throw new RuntimeException('Database backups are not implemented for the ['.$driver.'] driver.');
    }

    private function wrappedColumns(array $columns, string $driver): string
    {
        return implode(', ', array_map(fn (string $column): string => $this->wrap($column, $driver), $columns));
    }

    private function wrap(string $value, string $driver): string
    {
        return $driver === 'sqlite' ? '"'.$value.'"' : '`'.$value.'`';
    }

    private function quoteValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) $pdo->quote((string) $value);
    }
}
