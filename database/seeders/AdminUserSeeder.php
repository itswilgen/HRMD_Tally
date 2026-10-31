<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development accounts only. The README documents creating the real first
 * admin interactively so a known password never ships to the office server.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@hrmd.test'],
            [
                'name' => 'HR Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $admin->syncRoles([UserRole::HrAdmin->value]);

        $staff = User::updateOrCreate(
            ['email' => 'staff@hrmd.test'],
            [
                'name' => 'HR Staff',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $staff->syncRoles([UserRole::HrStaff->value]);
    }
}
