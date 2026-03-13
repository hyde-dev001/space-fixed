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
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->foreignId('parent_message_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained('conversation_messages')
                ->nullOnDelete();

            $table->index(['conversation_id', 'parent_message_id'], 'conversation_messages_conversation_parent_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropIndex('conversation_messages_conversation_parent_index');
            $table->dropConstrainedForeignId('parent_message_id');
        });
    }
};