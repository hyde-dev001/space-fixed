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
        Schema::create('hr_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            // Leave Type Configuration
            $table->string('leave_type', 50); // annual, sick, casual, maternity, paternity, etc.
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            
            // Accrual Settings
            $table->decimal('accrual_rate', 5, 2)->default(0); // Days per month
            $table->enum('accrual_frequency', ['monthly', 'quarterly', 'annually', 'on_joining'])->default('monthly');
            $table->integer('max_balance')->nullable(); // Maximum days that can be accumulated
            $table->integer('max_carry_forward')->default(0); // Days that can carry to next year
            $table->boolean('carry_forward_expires')->default(false);
            $table->integer('carry_forward_expiry_months')->nullable(); // Months before carried forward days expire
            
            // Eligibility & Requirements
            $table->integer('min_service_days')->default(0); // Minimum service required
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->integer('min_notice_days')->default(0); // Notice required before taking leave
            $table->integer('min_days')->default(1); // Minimum leave duration
            $table->integer('max_days')->default(365); // Maximum leave duration
            $table->boolean('allow_half_day')->default(false);
            
            // Restrictions
            $table->boolean('requires_document')->default(false); // Medical certificate, etc.
            $table->integer('document_required_after_days')->nullable(); // Days after which document is required
            $table->boolean('allow_negative_balance')->default(false);
            $table->decimal('negative_balance_limit', 5, 2)->default(0);
            
            // Gender & Role Restrictions
            $table->enum('applicable_gender', ['all', 'male', 'female'])->default('all');
            $table->json('applicable_departments')->nullable(); // Department IDs or null for all
            
            // Leave Encashment
            $table->boolean('is_encashable')->default(false);
            $table->integer('encashable_after_days')->nullable(); // Service days required
            $table->decimal('encashment_percentage', 5, 2)->default(100.00);
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // For ordering in UI
            
            $table->timestamps();
            
            // Indexes
            $table->index(['shop_owner_id', 'leave_type']);
            $table->index(['shop_owner_id', 'is_active']);
            
            // Unique constraint - one policy per leave type per shop
            $table->unique(['shop_owner_id', 'leave_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_leave_policies');
    }
};
