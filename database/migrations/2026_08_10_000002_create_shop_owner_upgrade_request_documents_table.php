<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_owner_upgrade_request_documents', function (Blueprint $table): void {
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
