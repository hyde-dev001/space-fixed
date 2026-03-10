<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('review_type', ['product', 'repair']);
            $table->unsignedBigInteger('review_id');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // reported customer
            $table->enum('reason', [
                'fake_review',
                'harassment',
                'spam',
                'inappropriate_content',
                'other',
            ]);
            $table->text('notes')->nullable();
            $table->json('review_snapshot'); // copy of review content at time of report
            $table->enum('status', [
                'pending_review',
                'under_investigation',
                'dismissed',
                'banned',
            ])->default('pending_review');
            $table->text('admin_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['review_type', 'review_id']);
            $table->index('status');
            $table->index('shop_owner_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};
