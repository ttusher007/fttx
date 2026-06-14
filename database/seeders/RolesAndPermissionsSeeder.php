<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions
        foreach (Permissions::definitions() as $slug => [$label, $group]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $label, 'group' => $group],
            );
        }

        // Roles + their permission sets
        foreach (Permissions::roleNames() as $slug => $name) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_system' => true],
            );

            $role->syncPermissionsBySlug(Permissions::roleDefaults()[$slug] ?? []);
        }
    }
}
