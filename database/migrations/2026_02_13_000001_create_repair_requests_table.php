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
        Schema::create('repair_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('customer_name');
            $table->string('email');
            $table->string('phone');
            $table->string('shoe_type')->nullable();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('shop_owner_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('assigned_repairer_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->enum('assignment_method', ['auto', 'manual'])->default('auto')->comment('How this repair was assigned');
            $table->unsignedBigInteger('assigned_by')->nullable()->comment('User ID who manually assigned (if manual)');
            $table->text('assignment_notes')->nullable()->comment('Reason for manual assignment/reassignment');
            $table->integer('reassignment_count')->default(0)->comment('Number of times this repair was reassigned');
            $table->timestamp('last_reassigned_at')->nullable()->comment('Last time this repair was reassigned');
            $table->unsignedBigInteger('assigned_manager_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            
            // Delivery & Logistics
            $table->enum('delivery_method', ['walk_in', 'pickup', 'delivery'])->nullable();
            $table->text('pickup_address')->nullable();
            $table->timestamp('scheduled_dropoff_date')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            
            // High Value Tracking
            $table->boolean('is_high_value')->default(false);
            $table->boolean('requires_owner_approval')->default(false);
            
            // Repairer Rejection
            $table->text('repairer_rejection_reason')->nullable();
            $table->timestamp('repairer_rejected_at')->nullable();
            $table->unsignedBigInteger('repairer_rejected_by')->nullable();
            
            // Manager Review
            $table->text('manager_review_notes')->nullable();
            $table->enum('manager_decision', ['approve_rejection', 'override_accept'])->nullable();
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->unsignedBigInteger('manager_reviewed_by')->nullable();
            
            // Owner Approval
            $table->text('owner_approval_notes')->nullable();
            $table->enum('owner_decision', ['approved', 'rejected'])->nullable();
            $table->timestamp('owner_reviewed_at')->nullable();
            $table->unsignedBigInteger('owner_reviewed_by')->nullable();
            
            // Work progress tracking
            $table->text('awaiting_parts_notes')->nullable();
            $table->timestamp('awaiting_parts_since')->nullable();
            $table->text('pickup_instructions')->nullable();
            
            $table->json('images')->nullable();
            $table->decimal('total', 10, 2);
            $table->enum('status', [
                'new_request',
                'assigned_to_repairer',
                'repairer_accepted',
                'awaiting_chat_confirmation',
                'customer_confirmed',
                'repairer_rejected',
                'manager_reviewing',
                'manager_approved',
                'manager_rejected',
                'pending_owner_approval',
                'owner_approved',
                'owner_rejected',
                'awaiting_item',
                'item_received',
                'received',
                'pending',
                'in-progress',
                'completed',
                'ready-for-pickup',
                'picked_up',
                'cancelled'
            ])->default('new_request');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_repairer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_manager_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('set null');
            $table->foreign('repairer_rejected_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('manager_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('owner_reviewed_by')->references('id')->on('shop_owners')->onDelete('set null');
        });

        Schema::create('repair_request_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repair_request_id');
            $table->unsignedBigInteger('repair_service_id');
            $table->timestamps();

            $table->foreign('repair_request_id')->references('id')->on('repair_requests')->onDelete('cascade');
            $table->foreign('repair_service_id')->references('id')->on('repair_services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repair_request_service');
        Schema::dropIfExists('repair_requests');
    }
};
