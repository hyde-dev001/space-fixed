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
        Schema::create('promo_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->enum('kind', ['voucher', 'sale']);
            $table->enum('scope', ['shop_wide', 'product_specific']);
            $table->string('name');
            $table->string('code')->nullable();
            $table->enum('discount_mode', ['percentage', 'fixed']);
            $table->decimal('value', 12, 2);
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('status', ['draft', 'scheduled', 'active', 'expired', 'disabled'])->default('draft');
            $table->enum('stacking_mode', ['combinable', 'exclusive'])->default('combinable');
            $table->timestamps();

            $table->index(['shop_owner_id', 'status']);
            $table->unique(['shop_owner_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_campaigns');
    }
};
