<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 30);
            $table->string('shop_type', 30)->nullable();
            $table->decimal('platform_fee_rate', 8, 6)->nullable();
            $table->boolean('platform_fee_vat_enabled')->nullable();
            $table->decimal('platform_fee_vat_rate', 8, 6)->nullable();
            $table->decimal('balance_limit', 14, 2)->nullable();
            $table->decimal('warning_threshold_percentage', 8, 4)->nullable();
            $table->decimal('critical_threshold_percentage', 8, 4)->nullable();
            $table->boolean('enforcement_enabled')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'shop_type']);
            $table->index(['scope', 'shop_type']);
        });

        Schema::create('shop_platform_fee_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->decimal('platform_fee_rate', 8, 6)->nullable();
            $table->boolean('platform_fee_vat_enabled')->nullable();
            $table->decimal('platform_fee_vat_rate', 8, 6)->nullable();
            $table->decimal('balance_limit', 14, 2)->nullable();
            $table->decimal('warning_threshold_percentage', 8, 4)->nullable();
            $table->decimal('critical_threshold_percentage', 8, 4)->nullable();
            $table->boolean('enforcement_enabled')->nullable();
            $table->string('status', 30)->default('approved');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique('shop_owner_id');
            $table->index(['shop_owner_id', 'status']);
        });

        Schema::create('platform_fee_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_origin', 30);
            $table->decimal('fee_base', 14, 2);
            $table->decimal('fee_rate', 8, 6);
            $table->decimal('platform_fee_amount', 14, 2);
            $table->boolean('vat_enabled')->default(true);
            $table->decimal('vat_rate', 8, 6)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_charge', 14, 2);
            $table->string('status', 30)->default('outstanding');
            $table->timestamp('finalized_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'source_origin']);
            $table->index(['shop_id', 'status', 'created_at']);
        });

        Schema::create('platform_fee_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('platform_fee_charge_id')->nullable()->constrained('platform_fee_charges')->nullOnDelete();
            $table->string('adjustment_type', 40);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('fee_base_delta', 14, 2)->default(0);
            $table->decimal('platform_fee_delta', 14, 2)->default(0);
            $table->decimal('vat_delta', 14, 2)->default(0);
            $table->decimal('total_delta', 14, 2);
            $table->string('reason', 500);
            $table->string('idempotency_key', 150)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(
                ['shop_id', 'adjustment_type', 'created_at'],
                'platform_fee_adjustments_shop_adjtype_created_idx',
            );
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('platform_credit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_origin', 30);
            $table->decimal('credit_amount', 14, 2);
            $table->string('status', 30)->default('available');
            $table->string('reason', 500);
            $table->string('idempotency_key', 150)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'source_origin'],
                'platform_credit_source_ref_unique',
            );
            $table->index(['shop_id', 'status', 'created_at']);
        });

        Schema::create('platform_fee_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('provider_checkout_id', 150)->nullable();
            $table->string('provider_payment_id', 150)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_snapshot', 14, 2);
            $table->decimal('credit_snapshot', 14, 2);
            $table->decimal('net_payable_snapshot', 14, 2);
            $table->string('status', 30)->default('pending');
            $table->string('idempotency_key', 150)->unique();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
            $table->index('provider_checkout_id');
            $table->index('provider_payment_id');
        });

        Schema::create('platform_fee_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_fee_payment_id')->nullable()->constrained('platform_fee_payments')->nullOnDelete();
            $table->foreignId('platform_fee_charge_id')->nullable()->constrained('platform_fee_charges')->nullOnDelete();
            $table->foreignId('platform_credit_application_id')->nullable();
            $table->foreign('platform_credit_application_id', 'platform_fee_alloc_credit_fk')
                ->references('id')
                ->on('platform_credit_applications')
                ->nullOnDelete();
            $table->string('allocation_type', 20);
            $table->decimal('amount', 14, 2);
            $table->string('idempotency_key', 150)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(
                ['platform_fee_payment_id', 'allocation_type'],
                'platform_fee_alloc_payment_type_idx',
            );
            $table->index(
                ['platform_credit_application_id', 'allocation_type'],
                'platform_fee_alloc_credit_type_idx',
            );
            $table->index('platform_fee_charge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_payment_allocations');
        Schema::dropIfExists('platform_fee_payments');
        Schema::dropIfExists('platform_credit_applications');
        Schema::dropIfExists('platform_fee_adjustments');
        Schema::dropIfExists('platform_fee_charges');
        Schema::dropIfExists('shop_platform_fee_settings');
        Schema::dropIfExists('platform_fee_settings');
    }
};
