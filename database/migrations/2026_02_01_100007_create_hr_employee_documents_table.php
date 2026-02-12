<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates employee document management system with:
     * - Document type categorization
     * - Expiry tracking for compliance
     * - Multi-tenant isolation
     * - Approval workflow support
     */
    public function up(): void
    {
        Schema::create('hr_employee_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('shop_owner_id');
            
            // Document Information
            $table->string('document_type', 50); // passport, visa, id_card, certificate, contract, etc.
            $table->string('document_number', 100)->nullable();
            $table->string('document_name', 255);
            $table->text('description')->nullable();
            
            // File Storage
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('file_type', 50); // pdf, jpg, png, doc, etc.
            $table->integer('file_size')->comment('Size in bytes');
            
            // Expiry & Compliance
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('requires_renewal')->default(false);
            $table->integer('reminder_days')->default(30)->comment('Days before expiry to send reminder');
            
            // Status Tracking
            $table->enum('status', ['pending', 'verified', 'rejected', 'expired'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            
            // Notification Tracking
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->integer('reminder_count')->default(0);
            
            // Audit Fields
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');
            
            $table->foreign('shop_owner_id')
                ->references('id')
                ->on('shop_owners')
                ->onDelete('cascade');
            
            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
            
            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            
            // Indexes for Performance
            $table->index(['employee_id', 'document_type']);
            $table->index(['shop_owner_id', 'status']);
            $table->index(['expiry_date', 'status']); // For expiry notifications
            $table->index(['employee_id', 'expiry_date']); // For employee document expiry checks
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_employee_documents');
    }
};
