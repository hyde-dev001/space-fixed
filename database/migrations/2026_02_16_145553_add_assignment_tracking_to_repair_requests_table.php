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
        Schema::table('repair_requests', function (Blueprint $table) {
            // Check if columns don't already exist before adding
            if (!Schema::hasColumn('repair_requests', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_repairer_id');
            }
            
            if (!Schema::hasColumn('repair_requests', 'assignment_method')) {
                $table->enum('assignment_method', ['auto', 'manual'])
                    ->default('auto')
                    ->after('assigned_at')
                    ->comment('How this repair was assigned');
            }
            
            if (!Schema::hasColumn('repair_requests', 'assigned_by')) {
                $table->unsignedBigInteger('assigned_by')
                    ->nullable()
                    ->after('assignment_method')
                    ->comment('User ID who manually assigned (if manual)');
                
                $table->foreign('assigned_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            }
            
            if (!Schema::hasColumn('repair_requests', 'assignment_notes')) {
                $table->text('assignment_notes')
                    ->nullable()
                    ->after('assigned_by')
                    ->comment('Reason for manual assignment/reassignment');
            }
            
            if (!Schema::hasColumn('repair_requests', 'reassignment_count')) {
                $table->integer('reassignment_count')
                    ->default(0)
                    ->after('assignment_notes')
                    ->comment('Number of times this repair was reassigned');
            }
            
            if (!Schema::hasColumn('repair_requests', 'last_reassigned_at')) {
                $table->timestamp('last_reassigned_at')
                    ->nullable()
                    ->after('reassignment_count')
                    ->comment('Last time this repair was reassigned');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn([
                'assigned_at',
                'assignment_method',
                'assigned_by',
                'assignment_notes',
                'reassignment_count',
                'last_reassigned_at'
            ]);
        });
    }
};
