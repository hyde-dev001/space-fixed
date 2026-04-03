<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no')->unique();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('module_type', 20)->default('repair');
            $table->unsignedBigInteger('module_reference_id');
            $table->enum('customer_type', ['registered', 'walk_in']);
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('walk_in_name')->nullable();
            $table->string('walk_in_phone', 30)->nullable();
            $table->string('walk_in_email')->nullable();
            $table->enum('due_type', ['deposit', 'balance', 'full', 'refund_adjustment']);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'partially_refunded', 'refunded', 'failed', 'voided'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module_type', 'module_reference_id']);
            $table->index(['shop_owner_id', 'status']);
        });

        Schema::create('pos_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_transaction_id')->constrained('pos_transactions')->cascadeOnDelete();
            $table->enum('tender_type', ['cash', 'paymongo_card', 'paymongo_wallet']);
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'paid', 'failed', 'reversed'])->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_transaction_id')->unique()->constrained('pos_transactions')->cascadeOnDelete();
            $table->string('receipt_no')->unique();
            $table->string('official_series')->nullable();
            $table->timestamp('issued_at');
            $table->json('print_payload');
            $table->json('digital_payload');
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_email_at')->nullable();
            $table->timestamp('sent_sms_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_no')->unique();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('source_transaction_id')->constrained('pos_transactions')->cascadeOnDelete();
            $table->string('module_type', 20)->default('repair');
            $table->unsignedBigInteger('module_reference_id');
            $table->enum('request_type', ['full', 'partial']);
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->string('reason_code');
            $table->text('reason_notes')->nullable();
            $table->enum('status', ['requested', 'approved', 'rejected', 'processing', 'succeeded', 'failed', 'cancelled'])->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['module_type', 'module_reference_id']);
        });

        Schema::create('pos_refund_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_refund_id')->constrained('pos_refunds')->cascadeOnDelete();
            $table->foreignId('source_payment_line_id')->constrained('pos_payment_lines')->cascadeOnDelete();
            $table->decimal('refunded_amount', 12, 2);
            $table->timestamps();
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->string('payment_policy_snapshot', 20)->nullable()->after('payment_policy');
            $table->string('payment_status_derived', 30)->nullable()->after('payment_status');
            $table->decimal('total_paid_amount', 12, 2)->default(0)->after('payment_status_derived');
            $table->decimal('total_refunded_amount', 12, 2)->default(0)->after('total_paid_amount');
            $table->unsignedBigInteger('latest_pos_transaction_id')->nullable()->after('total_refunded_amount');
            $table->index('latest_pos_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropIndex(['latest_pos_transaction_id']);
            $table->dropColumn([
                'payment_policy_snapshot',
                'payment_status_derived',
                'total_paid_amount',
                'total_refunded_amount',
                'latest_pos_transaction_id',
            ]);
        });

        Schema::dropIfExists('pos_refund_lines');
        Schema::dropIfExists('pos_refunds');
        Schema::dropIfExists('pos_receipts');
        Schema::dropIfExists('pos_payment_lines');
        Schema::dropIfExists('pos_transactions');
    }
};
