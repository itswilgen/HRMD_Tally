<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Philippine national holidays for 2026.
 *
 * The seven regular holidays with fixed dates are seeded once as recurring
 * rows: they are matched on month and day, so they apply to 2027 and every
 * year after without anyone re-entering them (HR answered 2026-10-31).
 * Everything else is dated and belongs to 2026 alone.
 *
 * Deliberately NOT seeded: Eid'l Fitr and Eid'l Adha, whose dates follow the
 * Islamic calendar and are fixed each year by presidential proclamation, and
 * any local charter-day holiday. Guessing those would silently produce wrong
 * leave ranges, so HR enters them in Settings once proclaimed.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{date: string, name: string, kind: HolidayKind}> $holidays */
        $holidays = [
            // Regular holidays, fixed date — entered once, recur every year.
            ['date' => '2026-01-01', 'name' => 'New Year\'s Day', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-04-09', 'name' => 'Araw ng Kagitingan', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-05-01', 'name' => 'Labor Day', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-06-12', 'name' => 'Independence Day', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-11-30', 'name' => 'Bonifacio Day', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-12-25', 'name' => 'Christmas Day', 'kind' => HolidayKind::RegularFixed],
            ['date' => '2026-12-30', 'name' => 'Rizal Day', 'kind' => HolidayKind::RegularFixed],

            // Regular holidays that move every year.
            ['date' => '2026-04-02', 'name' => 'Maundy Thursday', 'kind' => HolidayKind::RegularMovable],
            ['date' => '2026-04-03', 'name' => 'Good Friday', 'kind' => HolidayKind::RegularMovable],
            ['date' => '2026-08-31', 'name' => 'National Heroes Day', 'kind' => HolidayKind::RegularMovable],

            // Special non-working days, proclaimed yearly.
            ['date' => '2026-04-04', 'name' => 'Black Saturday', 'kind' => HolidayKind::SpecialNonWorking],
            ['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day', 'kind' => HolidayKind::SpecialNonWorking],
            ['date' => '2026-11-01', 'name' => 'All Saints\' Day', 'kind' => HolidayKind::SpecialNonWorking],
            ['date' => '2026-12-08', 'name' => 'Feast of the Immaculate Conception', 'kind' => HolidayKind::SpecialNonWorking],
            ['date' => '2026-12-31', 'name' => 'Last Day of the Year', 'kind' => HolidayKind::SpecialNonWorking],
        ];

        foreach ($holidays as $holiday) {
            Holiday::updateOrCreate(
                ['date' => $holiday['date']],
                ['name' => $holiday['name'], 'kind' => $holiday['kind']],
            );
        }
    }
}
