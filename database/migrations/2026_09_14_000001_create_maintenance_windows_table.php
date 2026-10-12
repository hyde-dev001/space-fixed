<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('public_message');
            $table->text('internal_note')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('notify_before_minutes')->default(15);
            $table->unsignedSmallInteger('transaction_freeze_minutes')->nullable();
            $table->string('progress_stage', 80)->nullable();
            $table->text('public_update_message')->nullable();
            $table->timestamp('public_update_updated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
