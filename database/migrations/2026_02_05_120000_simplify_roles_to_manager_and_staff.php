<?php
// database/migrations/2026_02_05_120000_simplify_roles_to_manager_and_staff.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add position column to users table if it doesn't exist
        if (!Schema::hasColumn('users', 'position')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('position')->nullable()->after('role');
            });
        }

        // Migrate existing users to new simplified roles BEFORE changing the enum
        // HR, CRM, Finance Manager, Payroll Manager -> MANAGER
        DB::table('users')
            ->whereIn('role', ['HR', 'CRM', 'FINANCE_MANAGER', 'PAYROLL_MANAGER'])
            ->update(['role' => 'MANAGER']);

        // Finance Staff -> STAFF with position "Finance Officer"
        DB::table('users')
            ->where('role', 'FINANCE_STAFF')
            ->update([
                'role' => 'STAFF',
                'position' => 'Finance Officer'
            ]);

        // Any other roles -> STAFF
        DB::table('users')
            ->whereNotIn('role', ['MANAGER', 'STAFF', 'SUPER_ADMIN'])
            ->whereNotNull('role')
            ->update(['role' => 'STAFF']);
        
        // NOW update the enum after all data is migrated
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('MANAGER', 'STAFF', 'SUPER_ADMIN') NULL");

        // Update Spatie roles - remove old department roles and reassign users
        $oldRoles = ['HR', 'CRM', 'Finance Staff', 'Finance Manager'];
        foreach ($oldRoles as $oldRole) {
            // Get users with this old role
            $users = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('roles.name', $oldRole)
                ->where('roles.guard_name', 'user')
                ->select('model_has_roles.*', 'roles.name as role_name')
                ->get();

            foreach ($users as $userRole) {
                // Assign new Manager role
                $managerRole = DB::table('roles')
                    ->where('name', 'Manager')
                    ->where('guard_name', 'user')
                    ->first();

                if ($managerRole) {
                    DB::table('model_has_roles')->updateOrInsert(
                        [
                            'role_id' => $managerRole->id,
                            'model_type' => $userRole->model_type,
                            'model_id' => $userRole->model_id,
                        ]
                    );
                }

                // Remove old role assignment
                DB::table('model_has_roles')
                    ->where('role_id', $userRole->role_id)
                    ->where('model_type', $userRole->model_type)
                    ->where('model_id', $userRole->model_id)
                    ->delete();
            }

            // Delete old role
            DB::table('roles')
                ->where('name', $oldRole)
                ->where('guard_name', 'user')
                ->delete();
        }
    }

    public function down(): void
    {
        // Restore original roles
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'CRM', 'MANAGER', 'STAFF', 'SUPER_ADMIN', 'PAYROLL_MANAGER') NULL");
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
