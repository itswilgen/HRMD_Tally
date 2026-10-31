<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the two roles and the permission matrix they grant.
 *
 * Re-running this is safe and is how a permission change is deployed: the rows
 * are matched by name, and each role's set is synced rather than appended to,
 * so a permission removed from UserRole::permissions() is revoked here too.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web')->syncPermissions(
                array_map(
                    static fn (PermissionEnum $permission): string => $permission->value,
                    $role->permissions(),
                )
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
