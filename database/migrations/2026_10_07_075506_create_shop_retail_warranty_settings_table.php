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
        Schema::create('shop_retail_warranty_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->unique()->constrained('shop_owners')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('title')->default('Product Warranty');
            $table->unsignedInteger('duration_value')->default(5);
            $table->string('duration_unit', 10)->default('days');
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->text('exclusions')->nullable();
            $table->text('instructions')->nullable();
            $table->dateTime('eligible_orders_from')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_retail_warranty_settings');
    }
};
