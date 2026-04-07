<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_refund_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_refund_id')->constrained('pos_refunds')->cascadeOnDelete();
            $table->enum('leg_type', ['gateway', 'pos_manual']);
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->enum('status', ['requested', 'approved', 'processing', 'succeeded', 'failed', 'rejected'])->default('requested');
            $table->unsignedBigInteger('source_transaction_id')->nullable();
            $table->string('source_receipt_no', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['pos_refund_id', 'leg_type']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_refund_legs');
    }
};
