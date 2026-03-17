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
        Schema::create('hr_thirteenth_month_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();

            $table->unsignedSmallInteger('accrual_year');
            $table->unsignedTinyInteger('accrual_month');

            $table->decimal('accrual_amount', 12, 2)->default(0);
            $table->decimal('release_amount', 12, 2)->default(0);
            $table->enum('status', ['accrued', 'partially_released', 'released'])->default('accrued');

            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reference', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['shop_owner_id', 'employee_id', 'accrual_year', 'accrual_month'], 'hr_13th_unique_monthly');
            $table->index(['shop_owner_id', 'accrual_year']);
            $table->index(['employee_id', 'accrual_year']);
            $table->index(['accrual_year', 'accrual_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_thirteenth_month_accruals');
    }
};
