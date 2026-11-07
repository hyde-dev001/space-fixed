<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::findOrCreate('operate-logistics-deliveries', 'user');
        Role::where('guard_name', 'user')->where('name', 'Logistics Rider')->each(
            fn (Role $role) => $role->givePermissionTo($permission)
        );
    }

    public function down(): void
    {
        $permission = Permission::findByName('operate-logistics-deliveries', 'user');
        Role::where('guard_name', 'user')->where('name', 'Logistics Rider')->each(
            fn (Role $role) => $role->revokePermissionTo($permission)
        );
    }
};
