<?php

namespace App\Services\Source;

use App\Contracts\Source\SourceDriverInterface;
use App\Models\SourceConnection;
use RuntimeException;

class SourceDriverRegistry
{
    /**
     * @var array<string, SourceDriverInterface>
     */
    private array $drivers;

    /**
     * @param  iterable<SourceDriverInterface>  $drivers
     */
    public function __construct(iterable $drivers)
    {
        $this->drivers = [];

        foreach ($drivers as $driver) {
            $this->drivers[$driver->driver()] = $driver;
        }
    }

    public function forConnection(SourceConnection $connection): SourceDriverInterface
    {
        return $this->forDriver($connection->driver);
    }

    public function forDriver(string $driver): SourceDriverInterface
    {
        if (! array_key_exists($driver, $this->drivers)) {
            throw new RuntimeException(sprintf('Unsupported source driver [%s].', $driver));
        }

        return $this->drivers[$driver];
    }
}
