<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

            return;
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_upgrade_request_id')
                ->constrained('shop_owner_upgrade_requests')
                ->cascadeOnDelete();
            $table->foreignId('source_shop_document_id')
                ->nullable()
                ->constrained('shop_documents')
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

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_upgrade_request_documents');
    }
};
