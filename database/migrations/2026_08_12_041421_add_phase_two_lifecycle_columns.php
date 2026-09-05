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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_suspension_id')->nullable()->after('status');
            $table->index('current_suspension_id');
            $table->softDeletes();
        });

        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_suspension_id')->nullable()->after('status');
            $table->index('current_suspension_id');
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->index('email', 'employees_email_lookup_index');
            $table->unsignedBigInteger('privileged_suspension_id')->nullable()->unique()->after('status');
        });

        Schema::table('suspension_appeals', function (Blueprint $table): void {
            $table->string('status')->default('eligible')->change();
            $table->unsignedBigInteger('suspension_id')->nullable()->unique()->after('account_id');
            $table->unsignedBigInteger('reviewer_id')->nullable()->after('suspended_by_super_admin_id');
            $table->index(['account_type', 'account_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('current_suspension_id')
                ->references('id')
                ->on('account_suspensions')
                ->nullOnDelete();
        });

        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->foreign('current_suspension_id')
                ->references('id')
                ->on('account_suspensions')
                ->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('privileged_suspension_id')
                ->references('id')
                ->on('account_suspensions')
                ->nullOnDelete();
        });

        Schema::table('suspension_appeals', function (Blueprint $table): void {
            $table->foreign('suspension_id')
                ->references('id')
                ->on('account_suspensions')
                ->nullOnDelete();
            $table->foreign('reviewer_id')
                ->references('id')
                ->on('super_admins')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suspension_appeals', function (Blueprint $table): void {
            $table->dropForeign(['suspension_id']);
            $table->dropForeign(['reviewer_id']);
            $table->dropUnique(['suspension_id']);
            $table->dropIndex(['account_type', 'account_id', 'status']);
            $table->dropColumn(['suspension_id', 'reviewer_id']);
            $table->enum('status', ['eligible', 'submitted', 'approved', 'rejected', 'expired'])
                ->default('eligible')
                ->change();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['privileged_suspension_id']);
            $table->dropUnique(['privileged_suspension_id']);
            $table->dropIndex('employees_email_lookup_index');
            $table->dropColumn('privileged_suspension_id');
            $table->unique('email');
        });

        Schema::table('shop_owners', function (Blueprint $table): void {
            $table->dropForeign(['current_suspension_id']);
            $table->dropIndex(['current_suspension_id']);
            $table->dropColumn('current_suspension_id');
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['current_suspension_id']);
            $table->dropIndex(['current_suspension_id']);
            $table->dropColumn('current_suspension_id');
            $table->dropSoftDeletes();
        });
    }
};
