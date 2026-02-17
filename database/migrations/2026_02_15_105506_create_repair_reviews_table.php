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
        Schema::create('repair_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_request_id')->constrained('repair_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Customer who wrote review
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade'); // Shop being reviewed
            $table->foreignId('repairer_id')->nullable()->constrained('users')->onDelete('set null'); // Repairer who did the work
            
            // Review content
            $table->unsignedTinyInteger('rating')->comment('1-5 star rating');
            $table->text('review_text')->nullable();
            $table->json('review_images')->nullable()->comment('Optional photos of completed work');
            
            // Shop owner response
            $table->text('shop_response')->nullable();
            $table->timestamp('shop_responded_at')->nullable();
            
            // Review status
            $table->boolean('is_verified')->default(true)->comment('Verified purchase - customer actually got the repair');
            $table->boolean('is_visible')->default(true)->comment('Can be hidden by admin if inappropriate');
            
            // Helpful votes (optional for future)
            $table->unsignedInteger('helpful_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('shop_owner_id');
            $table->index('rating');
            $table->index(['shop_owner_id', 'is_visible']);
            
            // Ensure one review per repair
            $table->unique('repair_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repair_reviews');
    }
};
