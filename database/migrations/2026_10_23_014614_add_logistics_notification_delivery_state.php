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
        Schema::table('delivery_events', function (Blueprint $table) {
            $table->timestamp('notification_requested_at')->nullable();
            $table->timestamp('notifications_processed_at')->nullable();
            $table->unsignedInteger('notification_attempts')->default(0);
            $table->string('notification_last_error')->nullable();
            $table->index(['notification_requested_at', 'notifications_processed_at'], 'delivery_events_notification_pending');
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('logistics_delivery_key', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['logistics_delivery_key']);
            $table->dropColumn('logistics_delivery_key');
        });
        Schema::table('delivery_events', function (Blueprint $table) {
            $table->dropIndex('delivery_events_notification_pending');
            $table->dropColumn(['notification_requested_at', 'notifications_processed_at', 'notification_attempts', 'notification_last_error']);
        });
    }
};
