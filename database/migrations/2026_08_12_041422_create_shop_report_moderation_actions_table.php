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
        Schema::create('shop_report_moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('super_admins')->restrictOnDelete();
            $table->string('requested_action', 32);
            $table->string('applied_action', 32);
            $table->json('report_ids');
            $table->string('decision_key', 64)->nullable()->unique();
            $table->unsignedInteger('warning_strike_number')->nullable();
            $table->string('source', 32)->default('runtime');
            $table->unsignedBigInteger('legacy_audit_log_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'created_at']);
            $table->unique(['shop_owner_id', 'warning_strike_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_report_moderation_actions');
    }
};
