<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->nullable()->constrained('shop_owners')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('carrier_type')->default('internal');
            $table->boolean('requires_assignment')->default(false);
            $table->boolean('requires_tracking')->default(false);
            $table->boolean('requires_pickup_proof')->default(false);
            $table->boolean('requires_delivery_proof')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['shop_owner_id', 'code']);
        });

        Schema::create('courier_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->nullable()->constrained('shop_owners')->cascadeOnDelete();
            $table->string('name');
            $table->string('provider_type')->default('manual');
            $table->string('tracking_url_template')->nullable();
            $table->boolean('supports_api')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('rider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('rider_type')->default('employee');
            $table->string('linked_type')->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('availability_status')->default('available');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['shop_owner_id', 'availability_status']);
            $table->index(['linked_type', 'linked_id']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('purpose');
            $table->string('status')->default('requested');
            $table->string('requested_by_type')->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['shop_owner_id', 'status']);
        });

        Schema::create('shipment_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('leg_type')->default('outbound');
            $table->string('status')->default('pending');
            $table->json('origin_snapshot')->nullable();
            $table->json('destination_snapshot')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();
            $table->string('provider_status')->nullable();
            $table->timestamp('scheduled_pickup_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->boolean('requires_pickup_proof')->default(false);
            $table->boolean('requires_delivery_proof')->default(true);
            $table->timestamps();

            $table->index('status');
            $table->index(['shipment_id', 'sequence']);
        });

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->cascadeOnDelete();
            $table->string('assignment_type');
            $table->foreignId('rider_profile_id')->nullable()->constrained('rider_profiles')->nullOnDelete();
            $table->foreignId('courier_provider_id')->nullable()->constrained('courier_providers')->nullOnDelete();
            $table->string('assigned_by_type')->nullable();
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->string('status')->default('assigned');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shipment_leg_id', 'status']);
        });

        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('shipment_leg_id')->nullable()->constrained('shipment_legs')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('visibility')->default('internal');
            $table->string('message')->nullable();
            $table->json('metadata')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'event_type']);
            $table->index(['shipment_leg_id', 'event_type']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->cascadeOnDelete();
            $table->string('attempt_type');
            $table->string('status')->default('failed');
            $table->string('reason_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('recorded_by_type')->nullable();
            $table->unsignedBigInteger('recorded_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('handoff_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_leg_id')->constrained('shipment_legs')->cascadeOnDelete();
            $table->string('handoff_type');
            $table->string('proof_type');
            $table->string('file_path')->nullable();
            $table->string('confirmed_by_type')->nullable();
            $table->unsignedBigInteger('confirmed_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['shipment_leg_id', 'handoff_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_proofs');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('delivery_events');
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('shipment_legs');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('rider_profiles');
        Schema::dropIfExists('courier_providers');
        Schema::dropIfExists('shipping_methods');
    }
};
