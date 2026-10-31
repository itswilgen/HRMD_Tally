<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Load data for the acceptance criterion "the tally page loads in under 2
 * seconds with 1,000+ employees and 100,000+ leave records".
 *
 * Not part of DatabaseSeeder. Run on demand:
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * Records are spread over several years on purpose, so the single-year tally
 * query has to prove it is using the (employee_id, leave_date) index rather
 * than scanning every row it owns.
 */
class PerformanceSeeder extends Seeder
{
    private const EMPLOYEES = 1_000;

    private const RECORDS_PER_EMPLOYEE = 100;

    private const FIRST_YEAR = 2022;

    private const LAST_YEAR = 2026;

    public function run(): void
    {
        $officeIds = Office::query()->pluck('id')->all();
        $leaveTypeIds = LeaveType::query()->active()->pluck('id')->all();
        $encoderIds = User::query()->pluck('id')->all();

        if ($officeIds === [] || $leaveTypeIds === [] || $encoderIds === []) {
            $this->command->error('Run the base seeders first: php artisan db:seed');

            return;
        }

        $this->command->info(sprintf('Creating %d employees...', self::EMPLOYEES));

        $employeeIds = [];

        foreach (array_chunk(range(1, self::EMPLOYEES), 250) as $chunk) {
            $rows = Employee::factory()
                ->count(count($chunk))
                ->make()
                ->map(function (Employee $employee) use ($officeIds): array {
                    $attributes = $employee->getAttributes();
                    $attributes['office_id'] = $officeIds[array_rand($officeIds)];
                    $attributes['created_at'] = now();
                    $attributes['updated_at'] = now();

                    return $attributes;
                })
                ->all();

            DB::table('employees')->insert($rows);
        }

        $employeeIds = Employee::query()->pluck('id')->all();
        $pool = $this->workingDayPool();

        $this->command->info(sprintf(
            'Creating ~%d leave records across %d-%d...',
            count($employeeIds) * self::RECORDS_PER_EMPLOYEE,
            self::FIRST_YEAR,
            self::LAST_YEAR,
        ));

        $bar = $this->command->getOutput()->createProgressBar(count($employeeIds));
        $bar->start();

        $rows = [];
        $now = now();

        foreach ($employeeIds as $employeeId) {
            // array_rand without replacement guarantees distinct dates, which is
            // what the partial unique index on (employee_id, leave_date,
            // day_part) requires.
            $dates = (array) array_rand($pool, self::RECORDS_PER_EMPLOYEE);

            foreach ($dates as $index) {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'leave_type_id' => $leaveTypeIds[array_rand($leaveTypeIds)],
                    'leave_date' => $pool[$index],
                    'day_part' => random_int(1, 100) <= 12
                        ? DayPart::Am->value
                        : DayPart::Whole->value,
                    'remarks' => null,
                    'encoded_by' => $encoderIds[array_rand($encoderIds)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($rows) >= 5_000) {
                DB::table('leave_records')->insert($rows);
                $rows = [];
            }

            $bar->advance();
        }

        if ($rows !== []) {
            DB::table('leave_records')->insert($rows);
        }

        $bar->finish();
        $this->command->newLine(2);

        $this->command->info(sprintf(
            'Done. %d employees, %d leave records.',
            DB::table('employees')->count(),
            DB::table('leave_records')->count(),
        ));
    }

    /**
     * Weekdays across the seeded year span, minus any known holiday.
     *
     * @return list<string>
     */
    private function workingDayPool(): array
    {
        $holidays = array_fill_keys(
            Holiday::query()->pluck('date')->map(static fn ($date): string => $date->format('Y-m-d'))->all(),
            true,
        );

        $days = [];

        $period = CarbonPeriod::create(
            sprintf('%d-01-01', self::FIRST_YEAR),
            sprintf('%d-12-31', self::LAST_YEAR),
        );

        foreach ($period as $date) {
            if ($date->isWeekend()) {
                continue;
            }

            $key = $date->format('Y-m-d');

            if (isset($holidays[$key])) {
                continue;
            }

            $days[] = $key;
        }

        return $days;
    }
}
