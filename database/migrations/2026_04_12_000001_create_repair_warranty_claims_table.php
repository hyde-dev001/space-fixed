<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_no')->unique();
            $table->foreignId('original_repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
            $table->foreignId('approved_repair_request_id')->nullable()->constrained('repair_requests')->nullOnDelete();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shop_owner_id')->nullable()->constrained('shop_owners')->nullOnDelete();
            $table->foreignId('repair_handler_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handler_source', 32)->nullable(); // individual_owner | business_employee
            $table->string('status', 32)->default('pending_repairer');
            $table->string('reason_code', 100);
            $table->text('reason_details')->nullable();
            $table->boolean('same_issue_confirmation')->default(false);
            $table->json('evidence_media')->nullable();
            $table->string('preferred_return_method', 32)->nullable(); // walk_in | customer_delivery
            $table->string('shipping_cost_bearer', 32)->default('customer');
            $table->string('source_channel', 32)->default('customer_portal'); // customer_portal | manual_pos_walk_in
            $table->timestamp('warranty_started_at_snapshot')->nullable();
            $table->timestamp('warranty_expires_at_snapshot')->nullable();
            $table->foreignId('reviewed_by_repairer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedTinyInteger('approved_once_guard')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'status'], 'repair_warranty_claim_shop_status_idx');
            $table->index(['repair_handler_user_id', 'status'], 'repair_warranty_claim_handler_status_idx');
            $table->index(['original_repair_request_id', 'status'], 'repair_warranty_claim_original_status_idx');
            $table->unique(['original_repair_request_id', 'approved_once_guard'], 'repair_warranty_claim_single_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_warranty_claims');
    }
};
