<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private array $permissionNames = [
        'inventory.view',
        'inventory.create',
        'inventory.edit',
    ];

    public function up(): void
    {
        foreach ($this->permissionNames as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'user');

            Role::query()
                ->where('guard_name', 'user')
                ->where('name', 'Inventory Manager')
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
    }

    public function down(): void
    {
        Role::query()
            ->where('guard_name', 'user')
            ->where('name', 'Inventory Manager')
            ->each(function (Role $role): void {
                foreach ($this->permissionNames as $permissionName) {
                    $permission = Permission::where('name', $permissionName)
                        ->where('guard_name', 'user')
                        ->first();

                    if ($permission) {
                        $role->revokePermissionTo($permission);
                    }
                }
            });
    }
};
