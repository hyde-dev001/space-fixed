<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_employment_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('end_reason')->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('functional_role')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
            $table->index(['shop_owner_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_employment_periods');
    }
};
