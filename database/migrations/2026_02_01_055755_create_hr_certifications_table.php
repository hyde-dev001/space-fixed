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
        Schema::create('hr_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('training_enrollment_id')->nullable()->constrained('hr_training_enrollments')->onDelete('set null');
            $table->string('certificate_name');
            $table->string('certificate_number')->unique();
            $table->string('issuing_organization');
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['active', 'expired', 'revoked', 'pending_renewal'])->default('active');
            $table->string('certificate_file_path')->nullable();
            $table->text('verification_url')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('shop_owner_id');
            $table->timestamps();
            
            $table->index(['employee_id', 'status']);
            $table->index(['shop_owner_id', 'expiry_date']);
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_certifications');
    }
};
