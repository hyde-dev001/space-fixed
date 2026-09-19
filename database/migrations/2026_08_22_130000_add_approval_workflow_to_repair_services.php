<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_services', function (Blueprint $table): void {
            if (!Schema::hasColumn('repair_services', 'approval_workflow_version')) {
                $table->string('approval_workflow_version')->nullable()->after('change_reason');
            }

            if (!Schema::hasColumn('repair_services', 'current_approval_level')) {
                $table->integer('current_approval_level')->nullable()->after('approval_workflow_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_services', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('repair_services', 'approval_workflow_version')) {
                $columns[] = 'approval_workflow_version';
            }

            if (Schema::hasColumn('repair_services', 'current_approval_level')) {
                $columns[] = 'current_approval_level';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
