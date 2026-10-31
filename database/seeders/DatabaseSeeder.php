<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: lookups and users exist before the rows that reference them.
     *
     * PerformanceSeeder is intentionally not called here — it is the 1,000
     * employee / 100,000 record load test, run on demand with
     * `php artisan db:seed --class=PerformanceSeeder`.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            OfficeSeeder::class,
            LeaveTypeSeeder::class,
            HolidaySeeder::class,
            EmployeeSeeder::class,
            LeaveRecordSeeder::class,
        ]);
    }
}
