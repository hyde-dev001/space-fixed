<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('activitylog.database_connection'));
        $table = config('activitylog.table_name', 'activity_log');

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->string('legacy_source')->nullable()->after('id');
            $blueprint->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $blueprint->unique(
                ['legacy_source', 'legacy_id'],
                'activity_log_legacy_provenance_unique',
            );
            $blueprint->index(
                ['log_name', 'created_at', 'id'],
                'activity_log_privileged_created_index',
            );
            $blueprint->index(
                ['log_name', 'event', 'created_at'],
                'activity_log_privileged_event_index',
            );
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('activitylog.database_connection'));
        $table = config('activitylog.table_name', 'activity_log');

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique('activity_log_legacy_provenance_unique');
            $blueprint->dropIndex('activity_log_privileged_created_index');
            $blueprint->dropIndex('activity_log_privileged_event_index');
            $blueprint->dropColumn(['legacy_source', 'legacy_id']);
        });
    }
};
