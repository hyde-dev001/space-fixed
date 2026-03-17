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
        Schema::create('hr_position_base_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->string('position_code', 100);
            $table->string('position_name', 120);
            $table->string('department', 120)->nullable();
            $table->decimal('monthly_rate', 12, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'is_active']);
            $table->index(['shop_owner_id', 'position_name']);
            $table->unique(['shop_owner_id', 'position_name', 'effective_from'], 'hr_position_rate_unique');
        });

        Schema::create('hr_holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->date('holiday_date');
            $table->string('holiday_name', 150);
            $table->enum('holiday_type', ['regular', 'special_non_working', 'special_working', 'local']);
            $table->boolean('is_paid')->default(true);
            $table->decimal('rate_multiplier', 5, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_owner_id', 'holiday_date']);
            $table->index(['shop_owner_id', 'holiday_type']);
            $table->unique(['shop_owner_id', 'holiday_date', 'holiday_name'], 'hr_holiday_unique');
        });

        Schema::create('hr_branch_payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->string('branch_name', 120);
            $table->enum('pay_cycle', ['semi_monthly'])->default('semi_monthly');
            $table->unsignedTinyInteger('pay_day_first')->default(15);
            $table->unsignedTinyInteger('pay_day_second')->default(30);
            $table->unsignedTinyInteger('standard_work_days_per_month')->default(26);
            $table->decimal('standard_work_hours_per_day', 4, 2)->default(8.00);
            $table->time('night_differential_start')->default('22:00:00');
            $table->time('night_differential_end')->default('06:00:00');
            $table->decimal('night_differential_rate', 5, 2)->default(0.10);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.25);
            $table->decimal('rest_day_multiplier', 5, 2)->default(1.30);
            $table->decimal('special_holiday_multiplier', 5, 2)->default(1.30);
            $table->decimal('regular_holiday_multiplier', 5, 2)->default(2.00);
            $table->string('non_business_day_rule', 80)->default('move_to_previous_business_day');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_owner_id', 'is_active']);
            $table->unique(['shop_owner_id', 'branch_name'], 'hr_branch_payroll_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_branch_payroll_settings');
        Schema::dropIfExists('hr_holiday_calendars');
        Schema::dropIfExists('hr_position_base_rates');
    }
};
