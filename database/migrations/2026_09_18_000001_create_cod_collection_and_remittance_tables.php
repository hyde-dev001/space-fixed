<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('shipment_leg_id')->nullable()->constrained('shipment_legs')->nullOnDelete();
            $table->foreignId('rider_profile_id')->nullable()->constrained('rider_profiles')->nullOnDelete();
            $table->foreignId('rider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_amount', 18, 2);
            $table->decimal('collected_amount', 18, 2)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('collection_reference')->nullable();
            $table->string('collection_idempotency_key')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'collection_reference'], 'cod_collections_shop_reference_unique');
            $table->unique(['shop_owner_id', 'collection_idempotency_key'], 'cod_collections_shop_key_unique');
            $table->index(['shop_owner_id', 'status'], 'cod_collections_shop_status_index');
            $table->index(['rider_profile_id', 'status'], 'cod_collections_rider_status_index');
        });

        Schema::create('cod_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('rider_profile_id')->nullable()->constrained('rider_profiles')->nullOnDelete();
            $table->foreignId('rider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference');
            $table->decimal('expected_amount', 18, 2);
            $table->decimal('submitted_amount', 18, 2);
            $table->decimal('received_amount', 18, 2)->nullable();
            $table->decimal('variance_amount', 18, 2)->nullable();
            $table->string('status', 32)->default('submitted');
            $table->string('idempotency_key');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('dispute_reason')->nullable();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'reference'], 'cod_remittances_shop_reference_unique');
            $table->unique(['shop_owner_id', 'idempotency_key'], 'cod_remittances_shop_key_unique');
            $table->index(['shop_owner_id', 'status'], 'cod_remittances_shop_status_index');
            $table->index(['rider_profile_id', 'status'], 'cod_remittances_rider_status_index');
        });

        Schema::create('cod_remittance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cod_remittance_id')->constrained('cod_remittances')->cascadeOnDelete();
            $table->foreignId('cod_collection_id')->unique()->constrained('cod_collections')->cascadeOnDelete();
            $table->decimal('expected_amount', 18, 2);
            $table->timestamps();

            $table->unique(
                ['cod_remittance_id', 'cod_collection_id'],
                'cod_remittance_items_remittance_collection_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_remittance_items');
        Schema::dropIfExists('cod_remittances');
        Schema::dropIfExists('cod_collections');
    }
};
