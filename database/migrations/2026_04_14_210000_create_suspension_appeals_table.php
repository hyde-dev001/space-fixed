<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspension_appeals', function (Blueprint $table) {
            $table->id();
            $table->enum('account_type', ['shop_owner', 'customer']);
            $table->unsignedBigInteger('account_id');
            $table->string('account_name')->nullable();
            $table->string('recipient_email');
            $table->text('suspension_reason')->nullable();
            $table->unsignedBigInteger('suspended_by_super_admin_id')->nullable();
            $table->enum('status', ['eligible', 'submitted', 'approved', 'rejected', 'expired'])->default('eligible');
            $table->string('appeal_token', 64)->unique();
            $table->text('appeal_message')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['account_type', 'account_id']);
            $table->index(['status', 'created_at']);
            $table->index('recipient_email');
            $table->foreign('suspended_by_super_admin_id')
                ->references('id')
                ->on('super_admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_appeals');
    }
};
