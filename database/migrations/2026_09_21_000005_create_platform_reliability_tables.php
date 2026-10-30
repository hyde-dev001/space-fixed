<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_reliability_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->date('score_date');
            $table->decimal('score', 5, 2);
            $table->json('factor_breakdown');
            $table->json('metrics')->nullable();
            $table->string('version', 40);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['shop_owner_id', 'score_date']);
            $table->index(['shop_owner_id', 'calculated_at']);
        });

        Schema::create('platform_fee_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('reliability_score_id')->nullable()->constrained('platform_reliability_scores')->nullOnDelete();
            $table->string('tier', 40);
            $table->decimal('current_limit', 14, 2);
            $table->decimal('recommended_limit', 14, 2);
            $table->string('status', 30)->default('pending');
            $table->json('rationale')->nullable();
            $table->unsignedBigInteger('reviewed_by_super_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('idempotency_key', 150)->unique();
            $table->timestamps();

            $table->index(
                ['shop_owner_id', 'status', 'created_at'],
                'platform_fee_recommendations_shop_status_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_recommendations');
        Schema::dropIfExists('platform_reliability_scores');
    }
};
