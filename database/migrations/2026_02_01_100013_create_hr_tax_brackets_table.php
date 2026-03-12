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
        Schema::create('hr_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            // Tax Bracket Details
            $table->string('bracket_name', 100); // "20% Bracket", "Standard Rate", etc.
            $table->text('description')->nullable();
            
            // Income Range
            $table->decimal('min_amount', 10, 2)->default(0);
            $table->decimal('max_amount', 10, 2)->nullable(); // NULL for unlimited (top bracket)
            
            // Tax Rate & Calculation
            $table->decimal('tax_rate', 5, 2); // Percentage (e.g., 15.00 for 15%)
            $table->decimal('fixed_amount', 10, 2)->default(0); // Fixed tax amount for this bracket
            $table->enum('calculation_type', ['progressive', 'flat'])->default('progressive');
            
            // Exemptions & Deductions
            $table->decimal('standard_deduction', 10, 2)->default(0);
            $table->decimal('personal_allowance', 10, 2)->default(0);
            
            // Applicability
            $table->enum('tax_type', ['income_tax', 'social_security', 'pension', 'other'])->default('income_tax');
            $table->enum('filing_status', ['single', 'married', 'all'])->default('all');
            $table->integer('tax_year')->nullable(); // NULL for always active, or specific year
            
            // Status & Ordering
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // For ordering brackets (lower first)
            
            // Effective Dates
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable(); // Additional rules, exemptions, etc.
            
            $table->timestamps();
            
            // Indexes
            $table->index(['shop_owner_id', 'is_active']);
            $table->index(['tax_type', 'filing_status', 'is_active']);
            $table->index(['min_amount', 'max_amount']);
            $table->index(['effective_from', 'effective_to']);
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_tax_brackets');
    }
};
