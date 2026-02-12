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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->year('year');
            
            // Leave balance for different types
            $table->integer('vacation_days')->default(15);
            $table->integer('sick_days')->default(10);
            $table->integer('personal_days')->default(5);
            $table->integer('maternity_days')->default(60);
            $table->integer('paternity_days')->default(7);
            
            // Used leave days
            $table->integer('used_vacation')->default(0);
            $table->integer('used_sick')->default(0);
            $table->integer('used_personal')->default(0);
            $table->integer('used_maternity')->default(0);
            $table->integer('used_paternity')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('employee_id');
            $table->index('shop_owner_id');
            $table->index('year');
            
            // Unique constraint for employee per year
            $table->unique(['employee_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};