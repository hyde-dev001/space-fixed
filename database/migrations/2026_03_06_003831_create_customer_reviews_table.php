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
        Schema::create('customer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->enum('order_type', ['product', 'repair'])->default('product');
            $table->string('service_type');
            $table->tinyInteger('rating')->unsigned()->default(5);
            $table->text('comment');
            $table->json('feedback_images')->nullable();
            $table->enum('response_status', ['pending', 'in_progress', 'responded'])->default('pending');
            $table->text('staff_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'response_status']);
            $table->index(['shop_owner_id', 'order_type']);
            $table->index('customer_id');
            $table->index('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_reviews');
    }
};
