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
        Schema::create('hr_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Multi-tenant isolation
            $table->foreignId('shop_owner_id')
                ->constrained('shop_owners')
                ->onDelete('cascade');
            
            // User who performed the action
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            // Employee affected by the action (if applicable)
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees', 'id')
                ->onDelete('set null');
            
            // Action details
            $table->string('module', 50); // employee, leave, payroll, attendance, performance, department, document
            $table->string('action', 100); // created, updated, deleted, approved, rejected, etc.
            $table->string('entity_type', 100)->nullable(); // Model class name (e.g., App\Models\Employee)
            $table->unsignedBigInteger('entity_id')->nullable(); // ID of the affected record
            
            // Description for human readability
            $table->text('description');
            
            // Change tracking
            $table->json('old_values')->nullable(); // Previous state (for updates)
            $table->json('new_values')->nullable(); // New state
            
            // Request metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_method', 10)->nullable(); // GET, POST, PUT, DELETE
            $table->text('request_url')->nullable();
            
            // Severity level
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            // info: Normal operations (view, list)
            // warning: Modifications (create, update)
            // critical: Sensitive actions (delete, approve payroll, suspend employee)
            
            // Tags for advanced filtering
            $table->json('tags')->nullable(); // ['sensitive', 'financial', 'compliance']
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['shop_owner_id', 'created_at'], 'idx_shop_date');
            $table->index(['user_id', 'created_at'], 'idx_user_date');
            $table->index(['employee_id', 'created_at'], 'idx_employee_date');
            $table->index(['module', 'action'], 'idx_module_action');
            $table->index(['entity_type', 'entity_id'], 'idx_entity');
            $table->index('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_audit_logs');
    }
};
