<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('disburse-payroll', 'user');

        Role::query()
            ->where('name', 'Finance')
            ->where('guard_name', 'user')
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', 'disburse-payroll')
            ->where('guard_name', 'user')
            ->first();

        if (! $permission) {
            return;
        }

        Role::query()
            ->where('name', 'Finance')
            ->where('guard_name', 'user')
            ->each(fn (Role $role) => $role->revokePermissionTo($permission));
    }
};
