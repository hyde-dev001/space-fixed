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
        Schema::create('shop_owner_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('premium_plan_id')->nullable()->constrained('premium_plans')->nullOnDelete();
            $table->string('plan_code');
            $table->unsignedInteger('showroom_slot_limit');
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled', 'failed'])->default('pending');
            $table->string('paymongo_session_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'status']);
            $table->index(['starts_at', 'ends_at']);
            $table->index('plan_code');
            $table->index('paymongo_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_owner_subscriptions');
    }
};
