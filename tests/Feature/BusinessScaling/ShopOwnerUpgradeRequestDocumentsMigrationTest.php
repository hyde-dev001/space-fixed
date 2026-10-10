<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use Illuminate\Database\Schema\Blueprint;
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

    public function test_documents_migration_repairs_missing_foreign_keys_on_an_existing_table(): void
    {
        $tableName = 'shop_owner_upgrade_request_documents';

        Schema::dropIfExists($tableName);
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_owner_upgrade_request_id');
            $table->unsignedBigInteger('source_shop_document_id')->nullable();
            $table->string('document_type', 100);
            $table->string('disk', 50);
            $table->string('path');
            $table->string('checksum_sha256', 64);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('source_status', 32);
            $table->timestamps();
        });

        $migration = require dirname(__DIR__, 3)
            . '/database/migrations/2026_08_10_000002_create_shop_owner_upgrade_request_documents_table.php';

        $migration->up();

        $foreignKeys = Schema::getForeignKeys($tableName);

        $this->assertTrue($this->hasForeignKey(
            $foreignKeys,
            'shop_owner_upgrade_request_id',
            'shop_owner_upgrade_requests',
        ));
        $this->assertTrue($this->hasForeignKey(
            $foreignKeys,
            'source_shop_document_id',
            'shop_documents',
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $foreignKeys
     */
    private function hasForeignKey(array $foreignKeys, string $column, string $table): bool
    {
        foreach ($foreignKeys as $foreignKey) {
            if (
                ($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === $table
            ) {
                return true;
            }
        }

        return false;
    }
}
