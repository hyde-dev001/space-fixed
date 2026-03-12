<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create shop_documents table
 * 
 * Stores uploaded verification documents for shop owner registrations.
 * Each shop owner must upload 4 required documents:
 * 
 * Required Documents:
 * - dti_registration: Business Registration (DTI/SEC)
 * - mayors_permit: Mayor's Permit / Business Permit
 * - bir_certificate: BIR Certificate of Registration (COR)
 * - valid_id: Valid Government ID of Business Owner
 * 
 * Documents are stored in storage/app/public/shop_documents/
 * Status is managed by super admin during review process.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the shop_documents table with foreign key to shop_owners
     */
    public function up()
    {
        Schema::create('shop_documents', function (Blueprint $table) {
            // <!-- Primary key -->
            $table->id();
            
            // <!-- Foreign key to shop_owners table -->
            // <!-- onDelete cascade: If shop owner is deleted, their documents are too -->
            $table->foreignId('shop_owner_id')
                ->constrained()
                ->onDelete('cascade');
            
            // <!-- Document Type -->
            // <!-- Possible values: dti_registration, mayors_permit, bir_certificate, valid_id -->
            $table->string('document_type');
            
            // <!-- File Storage Path -->
            // <!-- Relative path within storage/app/public/ -->
            // <!-- Example: shop_documents/xyz123.jpg -->
            $table->string('file_path');
            
            // <!-- Document Verification Status -->
            // <!-- pending: Awaiting admin review -->
            // <!-- approved: Document verified and accepted -->
            // <!-- rejected: Document rejected (invalid/fraudulent) -->
            $table->string('status')->default('pending');
            
            // <!-- Timestamps (created_at, updated_at) -->
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_documents');
    }
};
