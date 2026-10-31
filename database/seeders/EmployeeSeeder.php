<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * Fictional employees for development and the OJT defense. No real personal
 * data is ever used outside production (RA 10173).
 */
class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data is created once. Re-running `db:seed` should not keep piling
        // up fictional employees.
        if (Employee::query()->withTrashed()->exists()) {
            $this->command->info('  Employees already present, skipping.');

            return;
        }

        $offices = Office::query()->pluck('id');

        if ($offices->isEmpty()) {
            $offices = Office::factory()->count(3)->create()->pluck('id');
        }

        // A handful of inactive employees so the "hidden from encoding but kept
        // in past tallies" rule has something to exercise.
        Employee::factory()
            ->count(72)
            ->sequence(fn (object $sequence): array => ['office_id' => $offices->random()])
            ->create();

        Employee::factory()
            ->count(4)
            ->inactive()
            ->sequence(fn (object $sequence): array => ['office_id' => $offices->random()])
            ->create();
    }
}
