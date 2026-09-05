<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UPGRADE_REQUEST_FOREIGN_KEY = 'sourd_request_fk';

    private const SOURCE_DOCUMENT_FOREIGN_KEY = 'sourd_source_document_fk';

    public function up(): void
    {
        $tableName = 'shop_owner_upgrade_request_documents';

        // MySQL implicitly commits CREATE TABLE statements. If a deployment
        // stops after the DDL succeeds but before Laravel records the
        // migration, a retry must accept the already-created table instead of
        // attempting the same CREATE TABLE again.
        if (Schema::hasTable($tableName)) {
            $requiredColumns = [
                'id',
                'shop_owner_upgrade_request_id',
                'source_shop_document_id',
                'document_type',
                'disk',
                'path',
                'checksum_sha256',
                'mime_type',
                'size',
                'source_status',
                'created_at',
                'updated_at',
            ];
            $missingColumns = array_values(array_diff($requiredColumns, Schema::getColumnListing($tableName)));

            if ($missingColumns !== []) {
                throw new RuntimeException(sprintf(
                    'The %s table already exists but is missing required columns: %s. Reconcile the schema before retrying this migration.',
                    $tableName,
                    implode(', ', $missingColumns),
                ));
            }

            $this->addMissingForeignKeys($tableName);

            return;
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_upgrade_request_id');
            $table->foreign('shop_owner_upgrade_request_id', self::UPGRADE_REQUEST_FOREIGN_KEY)
                ->references('id')
                ->on('shop_owner_upgrade_requests')
                ->cascadeOnDelete();
            $table->foreignId('source_shop_document_id')->nullable();
            $table->foreign('source_shop_document_id', self::SOURCE_DOCUMENT_FOREIGN_KEY)
                ->references('id')
                ->on('shop_documents')
                ->nullOnDelete();
            $table->string('document_type', 100);
            $table->string('disk', 50);
            $table->string('path');
            $table->string('checksum_sha256', 64);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('source_status', 32);
            $table->timestamps();

            $table->index(['shop_owner_upgrade_request_id', 'document_type']);
        });
    }

    private function addMissingForeignKeys(string $tableName): void
    {
        $foreignKeys = Schema::getForeignKeys($tableName);
        $hasForeignKey = static function (string $column, string $foreignTable) use ($foreignKeys): bool {
            foreach ($foreignKeys as $foreignKey) {
                if (
                    ($foreignKey['columns'] ?? []) === [$column]
                    && ($foreignKey['foreign_table'] ?? null) === $foreignTable
                ) {
                    return true;
                }
            }

            return false;
        };

        Schema::table($tableName, function (Blueprint $table) use ($hasForeignKey): void {
            if (! $hasForeignKey('shop_owner_upgrade_request_id', 'shop_owner_upgrade_requests')) {
                $table->foreign('shop_owner_upgrade_request_id', self::UPGRADE_REQUEST_FOREIGN_KEY)
                    ->references('id')
                    ->on('shop_owner_upgrade_requests')
                    ->cascadeOnDelete();
            }

            if (! $hasForeignKey('source_shop_document_id', 'shop_documents')) {
                $table->foreign('source_shop_document_id', self::SOURCE_DOCUMENT_FOREIGN_KEY)
                    ->references('id')
                    ->on('shop_documents')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_upgrade_request_documents');
    }
};
