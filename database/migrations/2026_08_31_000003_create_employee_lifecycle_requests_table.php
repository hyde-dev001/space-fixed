<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_lifecycle_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('request_type', 32);
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->string('status', 32)->default('pending_manager');

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_status', 32)->default('pending');
            $table->text('manager_note')->nullable();
            $table->timestamp('manager_reviewed_at')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('shop_owners')->nullOnDelete();
            $table->string('owner_status', 32)->default('pending');
            $table->text('owner_note')->nullable();
            $table->timestamp('owner_reviewed_at')->nullable();

            // These fields are populated only for a rehire request. They are
            // reviewed by Manager and Shop Owner before the account reopens.
            $table->date('rehire_start_date')->nullable();
            $table->string('rehire_position')->nullable();
            $table->string('rehire_department')->nullable();
            $table->string('rehire_functional_role')->nullable();
            $table->decimal('rehire_salary', 12, 2)->nullable();
            $table->string('rehire_role')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'request_type', 'status'], 'employee_lifecycle_employee_type_status_index');
            $table->index(['request_type', 'status'], 'employee_lifecycle_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_lifecycle_requests');
    }
};
