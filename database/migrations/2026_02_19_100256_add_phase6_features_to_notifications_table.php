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
        Schema::table('notifications', function (Blueprint $table) {
            // Priority system: high, medium, low
            $table->string('priority')->default('medium')->after('type');
            
            // Grouping: allow notifications to be grouped together
            $table->string('group_key')->nullable()->after('priority');
            
            // Action required flag
            $table->boolean('requires_action')->default(false)->after('is_read');
            
            // Archived flag for cleanup
            $table->boolean('is_archived')->default(false)->after('requires_action');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
            
            // Expiry date for auto-cleanup
            $table->timestamp('expires_at')->nullable()->after('archived_at');
            
            // Add indexes for new columns
            $table->index('priority');
            $table->index('group_key');
            $table->index('requires_action');
            $table->index('is_archived');
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            // Quiet hours settings
            $table->boolean('quiet_hours_enabled')->default(false)->after('browser_delegation_assigned');
            $table->time('quiet_hours_start')->nullable()->after('quiet_hours_enabled');
            $table->time('quiet_hours_end')->nullable()->after('quiet_hours_start');
            
            // Browser push notifications
            $table->boolean('browser_push_enabled')->default(false)->after('quiet_hours_end');
            $table->text('push_subscription')->nullable()->after('browser_push_enabled');
            
            // Notification grouping preference
            $table->boolean('group_notifications')->default(true)->after('push_subscription');
            
            // Auto-archive old notifications
            $table->boolean('auto_archive_enabled')->default(false)->after('group_notifications');
            $table->integer('auto_archive_days')->default(30)->after('auto_archive_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropIndex(['group_key']);
            $table->dropIndex(['requires_action']);
            $table->dropIndex(['is_archived']);
            
            $table->dropColumn([
                'priority',
                'group_key',
                'requires_action',
                'is_archived',
                'archived_at',
                'expires_at',
            ]);
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'quiet_hours_enabled',
                'quiet_hours_start',
                'quiet_hours_end',
                'browser_push_enabled',
                'push_subscription',
                'group_notifications',
                'auto_archive_enabled',
                'auto_archive_days',
            ]);
        });
    }
};
