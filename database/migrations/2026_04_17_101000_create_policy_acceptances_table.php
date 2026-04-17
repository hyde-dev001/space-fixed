<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('shop_policy_version_id')->constrained('shop_policy_versions')->cascadeOnDelete();
            $table->string('actor_guard', 20)->default('user');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('context_type', ['order', 'repair_request']);
            $table->unsignedBigInteger('context_id');
            $table->timestamp('accepted_at');
            $table->string('accepted_from_ip', 45)->nullable();
            $table->text('accepted_user_agent')->nullable();
            $table->char('accepted_snapshot_hash', 64);
            $table->timestamps();

            $table->index(['context_type', 'context_id']);
            $table->index(['shop_owner_id', 'actor_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acceptances');
    }
};
