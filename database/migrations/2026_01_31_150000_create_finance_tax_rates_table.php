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
        Schema::create('finance_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "VAT 12%", "Sales Tax 8%"
            $table->string('code')->unique(); // e.g., "VAT12", "GST"
            $table->decimal('rate', 5, 2); // Tax rate percentage (e.g., 12.00 for 12%)
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('fixed_amount', 18, 2)->nullable(); // For fixed tax amounts
            $table->text('description')->nullable();
            $table->enum('applies_to', ['all', 'expenses', 'invoices', 'journal_entries'])->default('all');
            $table->boolean('is_default')->default(false); // Default tax for new transactions
            $table->boolean('is_inclusive')->default(false); // Tax included in price or added on top
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable(); // When this rate becomes effective
            $table->date('effective_to')->nullable(); // When this rate expires
            $table->string('region')->nullable(); // For region-specific taxes
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->json('meta')->nullable(); // Additional configuration
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shop_id', 'is_active']);
            $table->index(['code', 'shop_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_tax_rates');
    }
};
