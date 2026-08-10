<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ShopOwnerUpgradeRequestDocumentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_migration_is_retryable_when_mysql_created_the_table_before_recording_it(): void
    {
        $migration = require dirname(__DIR__, 3)
            . '/database/migrations/2026_08_10_000002_create_shop_owner_upgrade_request_documents_table.php';

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('shop_owner_upgrade_request_documents'));
    }
}
