<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo leave for the current year.
 *
 * Generates short consecutive runs as well as single days, so the tally text
 * built in Phase 6 has both "23-25 MFL" and "4, 9 SL" shapes to render.
 *
 * Range expansion here is deliberately minimal and local to the seeder; the
 * real rules (preview, conflict detection, year splitting) belong to
 * LeaveEncodingService in Phase 5.
 */
class LeaveRecordSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->year;
        $leaveTypeIds = LeaveType::query()->active()->pluck('id')->all();
        $encoderIds = User::query()->pluck('id')->all();

        if ($leaveTypeIds === [] || $encoderIds === []) {
            return;
        }

        $workingDays = $this->workingDays($year);

        if ($workingDays === []) {
            return;
        }

        // Employees who already have leave for this year are skipped, so running
        // `db:seed` a second time tops up new employees instead of colliding with
        // the unique index on (employee_id, leave_date, day_part).
        $alreadySeeded = array_fill_keys(
            LeaveRecord::query()->forYear($year)->distinct()->pluck('employee_id')->all(),
            true,
        );

        $rows = [];
        $now = now();

        Employee::query()->active()->eachById(function (Employee $employee) use (
            $workingDays, $leaveTypeIds, $encoderIds, $now, $alreadySeeded, &$rows
        ): void {
            if (isset($alreadySeeded[$employee->id])) {
                return;
            }

            $used = [];
            $events = random_int(0, 9);

            for ($i = 0; $i < $events; $i++) {
                $start = random_int(0, count($workingDays) - 1);
                $length = random_int(1, 3);

                // A single day is sometimes a half day; a multi-day run never is.
                $dayPart = $length === 1 && random_int(1, 100) <= 15
                    ? ($i % 2 === 0 ? DayPart::Am : DayPart::Pm)
                    : DayPart::Whole;

                $leaveTypeId = $leaveTypeIds[array_rand($leaveTypeIds)];

                for ($offset = 0; $offset < $length; $offset++) {
                    $date = $workingDays[$start + $offset] ?? null;

                    if ($date === null || isset($used[$date])) {
                        continue;
                    }

                    $used[$date] = true;

                    $rows[] = [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypeId,
                        'leave_date' => $date,
                        'day_part' => $dayPart->value,
                        'remarks' => null,
                        'encoded_by' => $encoderIds[array_rand($encoderIds)],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (count($rows) >= 1_000) {
                DB::table('leave_records')->insert($rows);
                $rows = [];
            }
        });

        if ($rows !== []) {
            DB::table('leave_records')->insert($rows);
        }

        $this->command->info(sprintf(
            '  Seeded %d leave records for %d.',
            LeaveRecord::query()->forYear($year)->count(),
            $year,
        ));
    }

    /**
     * Working days of the year up to today: weekends and holidays removed.
     *
     * @return list<string>
     */
    private function workingDays(int $year): array
    {
        $holidays = Holiday::query()
            ->forYear($year)
            ->pluck('date')
            ->map(static fn ($date): string => $date->format('Y-m-d'))
            ->all();

        $holidays = array_fill_keys($holidays, true);

        $end = now()->year === $year ? now()->startOfDay() : now()->setDate($year, 12, 31);

        $days = [];

        foreach (CarbonPeriod::create(sprintf('%d-01-01', $year), $end) as $date) {
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
