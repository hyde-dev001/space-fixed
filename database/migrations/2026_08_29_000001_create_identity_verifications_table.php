<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('document_type', 64)->nullable();
            $table->string('screening_status', 64)->default('pending');
            $table->string('review_status', 32)->default('not_required');
            $table->string('file_path');
            $table->string('file_disk', 32)->default('local');
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->string('failure_reason', 96)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['screening_status', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
