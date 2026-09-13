<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->string('payment_method', 32)->nullable();
            $table->string('status', 32)->default('initiating')->change();
            $table->timestamp('externally_paid_at')->nullable();
            $table->timestamp('submitted_for_verification_at')->nullable();
            $table->foreignId('verified_by_shop_owner_id')->nullable()->constrained('shop_owners')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('rejected_by_shop_owner_id')->nullable()->constrained('shop_owners')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('finance_note')->nullable();
            $table->string('supplier_email_to', 191)->nullable();
            $table->string('supplier_email_status', 16)->nullable();
            $table->timestamp('supplier_email_sent_at')->nullable();
            $table->timestamp('supplier_email_failed_at')->nullable();
            $table->text('supplier_email_failure_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by_shop_owner_id');
            $table->dropConstrainedForeignId('rejected_by_shop_owner_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn([
                'payment_method',
                'externally_paid_at',
                'submitted_for_verification_at',
                'verified_at',
                'rejected_at',
                'rejection_reason',
                'cancellation_reason',
                'cancelled_at',
                'finance_note',
                'supplier_email_to',
                'supplier_email_status',
                'supplier_email_sent_at',
                'supplier_email_failed_at',
                'supplier_email_failure_message',
            ]);
            $table->string('status', 20)->default('initiating')->change();
        });
    }
};
