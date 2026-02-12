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
        Schema::create('conversation_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('from_department', ['crm', 'repairer']);
            $table->foreignId('to_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->enum('to_department', ['crm', 'repairer']);
            $table->text('transfer_note')->nullable(); // Why this is being transferred
            $table->timestamps();

            // Index for conversation history
            $table->index('conversation_id');
            // Index for user transfer history
            $table->index(['from_user_id', 'created_at']);
            $table->index(['to_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_transfers');
    }
};
