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
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->enum('sender_type', ['customer', 'crm', 'repairer']);
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('content')->nullable(); // Nullable because message might be image-only
            $table->json('attachments')->nullable(); // Store image URLs/paths as JSON array
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Index for fetching messages by conversation
            $table->index('conversation_id');
            // Index for unread messages queries
            $table->index(['conversation_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
