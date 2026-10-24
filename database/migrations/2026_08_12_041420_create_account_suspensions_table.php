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
        Schema::create('account_suspensions', function (Blueprint $table) {
            $table->id();

            // Account identity is intentionally bounded instead of polymorphic.
            $table->string('account_type', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('source', 64)->default('runtime');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('suspended_by_super_admin_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by_super_admin_id')->nullable();
            $table->text('end_reason')->nullable();
            $table->unsignedBigInteger('linked_employee_id')->nullable();
            $table->string('linked_employee_prior_status', 32)->nullable();
            $table->timestamps();

            $table->index(['account_type', 'account_id', 'ended_at']);
            $table->foreign('suspended_by_super_admin_id')
                ->references('id')
                ->on('super_admins')
                ->nullOnDelete();
            $table->foreign('ended_by_super_admin_id')
                ->references('id')
                ->on('super_admins')
                ->nullOnDelete();
            $table->foreign('linked_employee_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_suspensions');
    }
};
