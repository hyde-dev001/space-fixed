<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_link_id')->nullable()->unique();
            $table->string('phase');
            $table->string('status')->default('pending');
            $table->string('snapshot_version', 64)->nullable();
            $table->string('delivery_method')->nullable();
            $table->decimal('service_amount', 10, 2)->default(0);
            $table->decimal('delivery_amount', 10, 2)->default(0);
            $table->json('quote')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['repair_request_id', 'phase', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_payment_sessions');
    }
};
