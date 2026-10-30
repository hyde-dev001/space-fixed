<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_threshold_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->string('threshold_key', 20);
            $table->boolean('crossed')->default(false);
            $table->decimal('utilization_percentage', 8, 2)->default(0);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'threshold_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_threshold_states');
    }
};
