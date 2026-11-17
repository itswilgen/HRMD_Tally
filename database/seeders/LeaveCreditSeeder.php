<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo leave credits for this year and last.
 *
 * PROVISIONAL figures, for the same reason LeaveTypeSeeder's codes are: the
 * standard yearly grant has not been confirmed with HR. The numbers below are
 * the familiar CSC shape (15 and 15, with the smaller privilege leaves at 3 to
 * 5), which is enough for the credits screen to have something to subtract
 * from. Phase 1 does not earn credits, so nothing computes these — an HR Admin
 * types what the leave card says, and this seeder stands in for that typing.
 *
 * Two years are seeded so "copy from last year" has a source to copy from and a
 * target with gaps in it. Fictional data only, per RA 10173.
 */
class LeaveCreditSeeder extends Seeder
{
    /**
     * Days granted per leave code. A code that is not listed is left blank on
     * purpose: a blank cell is a real state on the credits screen, and the demo
     * data should show one. CTO is absent because it is earned by working
     * overtime rather than granted yearly.
     */
    private const GRANTS = [
        'VL' => 15.0,
        'SL' => 15.0,
        'MFL' => 5.0,
        'SPL' => 3.0,
        'WL' => 3.0,
    ];

    public function run(): void
    {
        $year = (int) now()->year;

        $leaveTypeIds = LeaveType::query()
            ->active()
            ->whereIn('code', array_keys(self::GRANTS))
            ->pluck('id', 'code');

        $grantor = User::query()->value('id');

        if ($leaveTypeIds->isEmpty() || $grantor === null) {
            return;
        }

        $rows = [];
        $now = now();

        Employee::query()->active()->eachById(function (Employee $employee) use (
            $leaveTypeIds, $grantor, $year, $now, &$rows
        ): void {
            foreach (self::GRANTS as $code => $days) {
                $leaveTypeId = $leaveTypeIds->get($code);

                if ($leaveTypeId === null) {
                    continue;
                }

                // Last year's figures are seeded a day lighter for a couple of
                // the leaves, so the two years are visibly different sheets
                // rather than the same numbers twice.
                foreach ([$year => $days, $year - 1 => max(0.0, $days - ($days > 5.0 ? 0.0 : 0.5))] as $creditYear => $figure) {
                    $rows[] = [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypeId,
                        'year' => $creditYear,
                        'days' => $figure,
                        'remarks' => null,
                        'granted_by' => $grantor,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        });

        // upsert, not insert: running `db:seed` twice must top up new employees
        // rather than collide with the unique index on
        // (employee_id, leave_type_id, year). Chunked so the statement stays a
        // sane size on the 1,000 employee performance dataset.
        foreach (array_chunk($rows, 500) as $chunk) {
            LeaveCredit::query()->upsert(
                $chunk,
                ['employee_id', 'leave_type_id', 'year'],
                ['days', 'granted_by', 'updated_at'],
            );
        }
    }
}
