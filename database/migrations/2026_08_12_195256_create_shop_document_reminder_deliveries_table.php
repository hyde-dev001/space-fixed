<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_document_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_document_id')
                ->constrained('shop_documents')
                ->cascadeOnDelete();
            $table->string('expiration_identity', 191);
            $table->unsignedSmallInteger('threshold_days');
            $table->string('recipient_type', 32);
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->unique(
                ['shop_document_id', 'expiration_identity', 'threshold_days', 'recipient_type', 'recipient_id'],
                'shop_doc_reminder_delivery_unique',
            );
            $table->index(['recipient_type', 'recipient_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_document_reminder_deliveries');
    }
};
