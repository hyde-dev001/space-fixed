<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission Audit Log Table
 * 
 * Tracks all changes to roles and permissions for compliance and security auditing
 * Critical for regulatory compliance (GDPR, SOX, HIPAA, etc.)
 * 
 * Records:
 * - Role assignments/removals
 * - Permission grants/revocations
 * - Position template applications
 * - Who made the change
 * - When the change occurred
 * - Reason for the change (if provided)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Shop isolation
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            // Actor (who made the change)
            $table->foreignId('actor_id')->nullable()->comment('User who performed the action');
            $table->string('actor_type')->default('App\\Models\\User')->comment('Actor model type');
            $table->string('actor_name')->nullable()->comment('Actor name at time of action');
            
            // Subject (who was affected)
            $table->foreignId('subject_id')->comment('User who was affected');
            $table->string('subject_type')->default('App\\Models\\User')->comment('Subject model type');
            $table->string('subject_name')->comment('Subject name at time of action');
            
            // Change details
            $table->enum('action', [
                'role_assigned',
                'role_removed',
                'permission_granted',
                'permission_revoked',
                'position_assigned',
                'position_removed',
                'permissions_synced',
                'role_changed'
            ])->index();
            
            // What changed
            $table->string('role_name')->nullable()->comment('Role involved in change');
            $table->string('permission_name')->nullable()->comment('Permission involved in change');
            $table->string('position_name')->nullable()->comment('Position template applied');
            $table->unsignedInteger('position_template_id')->nullable();
            
            // Before/After state (JSON)
            $table->json('old_value')->nullable()->comment('State before change');
            $table->json('new_value')->nullable()->comment('State after change');
            
            // Context
            $table->text('reason')->nullable()->comment('Why the change was made');
            $table->text('notes')->nullable()->comment('Additional notes');
            
            // Compliance metadata
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_method')->nullable();
            $table->string('request_url')->nullable();
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at'); // For date range queries
            
            // Composite indexes for common queries
            $table->index(['shop_owner_id', 'subject_id', 'created_at']);
            $table->index(['shop_owner_id', 'action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
