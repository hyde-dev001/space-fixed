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
        // Drop existing notifications table if it exists (Laravel default)
        Schema::dropIfExists('notifications');
        
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type'); // expense_approval, leave_approval, invoice_created, etc.
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Additional context data
            $table->string('action_url')->nullable(); // Where to navigate when clicked
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'is_read']);
            $table->index('shop_id');
            $table->index('created_at');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('email_expense_approval')->default(true);
            $table->boolean('email_leave_approval')->default(true);
            $table->boolean('email_invoice_created')->default(false);
            $table->boolean('email_delegation_assigned')->default(true);
            $table->boolean('browser_expense_approval')->default(true);
            $table->boolean('browser_leave_approval')->default(true);
            $table->boolean('browser_invoice_created')->default(true);
            $table->boolean('browser_delegation_assigned')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
