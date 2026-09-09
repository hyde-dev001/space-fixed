<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (['manage-employee-accounts', 'reset-employee-mfa'] as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'user',
            ]);
        }

        foreach (['Manager', 'HR'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'user')
                ->first();

            if ($role) {
                $role->givePermissionTo(['manage-employee-accounts', 'reset-employee-mfa']);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()
            ->where('guard_name', 'user')
            ->whereIn('name', ['manage-employee-accounts', 'reset-employee-mfa'])
            ->delete();
    }
};
