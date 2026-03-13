<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_reports', function (Blueprint $table) {
            $table->id();

            // Who submitted the report
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Which shop is being reported
            $table->foreignId('shop_owner_id')
                ->constrained('shop_owners')
                ->onDelete('cascade');

            // Report details
            $table->enum('reason', [
                'fraud',
                'fake_products',
                'harassment',
                'no_show',
                'misconduct',
                'other',
            ]);
            $table->text('description'); // min 20 chars enforced in controller

            // Transaction proof (at least one is required)
            $table->string('transaction_type')->nullable(); // 'order' or 'repair'
            $table->unsignedBigInteger('transaction_id')->nullable(); // order.id or repair_request.id

            // Workflow status
            $table->enum('status', [
                'submitted',
                'under_review',
                'dismissed',
                'warned',
                'suspended',
            ])->default('submitted');

            // Super admin review
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable(); // super_admin.id
            $table->timestamp('reviewed_at')->nullable();

            // Anti-abuse: IP address for clustering detection
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // Prevent duplicate reports (one report per user per shop)
            $table->unique(['user_id', 'shop_owner_id']);

            // Indexes for efficient querying
            $table->index('shop_owner_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['shop_owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_reports');
    }
};
