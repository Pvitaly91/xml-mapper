<?php

namespace App\Console\Commands;

use App\Contracts\Source\SourceConnectionTestServiceInterface;
use App\Models\SourceConnection;
use Illuminate\Console\Command;

class SourceTestCommand extends Command
{
    protected $signature = 'source:test {sourceConnectionId : Source connection ID}';

    protected $description = 'Test connectivity and permissions for a source connection.';

    public function handle(SourceConnectionTestServiceInterface $tester): int
    {
        $connection = SourceConnection::findOrFail((int) $this->argument('sourceConnectionId'));
        $result = $tester->test($connection);

        $this->line(sprintf('Connection #%d [%s]: %s', $connection->id, $connection->driver, $result->message));

        if ($result->meta !== []) {
            $this->table(['key', 'value'], collect($result->meta)->map(fn ($value, $key) => [
                'key' => $key,
                'value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->values()->all());
        }

        return $result->status === SourceConnection::CHECK_STATUS_OK ? self::SUCCESS : self::FAILURE;
    }
}
