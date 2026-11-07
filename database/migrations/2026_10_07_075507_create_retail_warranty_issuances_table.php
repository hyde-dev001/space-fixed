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
        Schema::create('retail_warranty_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('warranty_number', 64)->unique();
            $table->json('shop_snapshot');
            $table->json('customer_snapshot');
            $table->json('order_snapshot')->nullable();
            $table->dateTime('fulfilled_at');
            $table->dateTime('issued_at');
            $table->string('business_timezone', 64);
            $table->string('certificate_path')->nullable();
            $table->string('certificate_hash', 64)->nullable();
            $table->dateTime('certificate_generated_at')->nullable();
            $table->json('certificate_status_at_generation')->nullable();
            $table->string('email_delivery_state', 16)->default('pending')->index();
            $table->uuid('email_attempt_token')->nullable();
            $table->dateTime('email_attempted_at')->nullable();
            $table->dateTime('email_sent_at')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('email_failure_code', 64)->nullable();
            $table->unsignedInteger('email_attempts')->default(0);
            $table->index(['shop_owner_id', 'issued_at']);
            $table->index(['customer_id', 'issued_at']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_warranty_issuances');
    }
};
