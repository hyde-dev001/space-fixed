<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait ResetsInMemoryDatabaseState
{
    protected function tearDown(): void
    {
        $connection = (string) config('database.default');
        $isInMemory = config("database.connections.{$connection}.database") === ':memory:';

        try {
            parent::tearDown();
        } finally {
            if ($isInMemory) {
                RefreshDatabaseState::$inMemoryConnections = [];
                RefreshDatabaseState::$migrated = false;
            }
        }
    }
}
