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
        Schema::create('price_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->decimal('current_price', 10, 2);
            $table->decimal('proposed_price', 10, 2);
            $table->text('reason');
            $table->unsignedBigInteger('requested_by'); // User (STAFF) who requested
            $table->enum('status', [
                'pending',
                'finance_approved',
                'finance_rejected',
                'owner_approved',
                'owner_rejected'
            ])->default('pending');
            
            // Finance review
            $table->unsignedBigInteger('finance_reviewed_by')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->text('finance_notes')->nullable();
            $table->text('finance_rejection_reason')->nullable();
            
            // Owner review
            $table->unsignedBigInteger('owner_reviewed_by')->nullable();
            $table->timestamp('owner_reviewed_at')->nullable();
            $table->text('owner_rejection_reason')->nullable();
            
            $table->unsignedBigInteger('shop_owner_id'); // Which shop this belongs to
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('finance_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('owner_reviewed_by')->references('id')->on('shop_owners')->onDelete('set null');
            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');
            
            // Indexes for common queries
            $table->index(['status', 'shop_owner_id']);
            $table->index(['product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_change_requests');
    }
};
