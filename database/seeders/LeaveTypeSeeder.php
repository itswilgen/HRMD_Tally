<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * PROVISIONAL leave types.
 *
 * SL, MFL, CTO and Wellness are the codes actually seen on HRMD's paper tally
 * sheet. VL and SPL are included because they are standard CSC leave benefits
 * and almost certainly in use, but neither the full list nor the exact codes
 * have been confirmed with HR yet (open question in
 * docs/PROJECT_CONTEXT.md section 11).
 *
 * Nothing here is hardcoded anywhere else in the system: HR Admin maintains
 * these rows in Settings, so correcting this list costs nothing.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // The colour is a chip token key, not a hex value: the palette lives
            // in resources/css/app.css. These assignments are the hues of the
            // approved tally mockup (docs/UI_SPEC.md), which is why vacation is
            // blue and wellness green rather than following the sort order.
            ['code' => 'VL', 'name' => 'Vacation Leave', 'color' => 'sky', 'sort_order' => 1],
            ['code' => 'SL', 'name' => 'Sick Leave', 'color' => 'amber', 'sort_order' => 2],
            ['code' => 'MFL', 'name' => 'Mandatory/Forced Leave', 'color' => 'violet', 'sort_order' => 3],
            ['code' => 'SPL', 'name' => 'Special Privilege Leave', 'color' => 'teal', 'sort_order' => 4],
            ['code' => 'CTO', 'name' => 'Compensatory Time Off', 'color' => 'slate', 'sort_order' => 5],
            ['code' => 'WL', 'name' => 'Wellness Leave', 'color' => 'emerald', 'sort_order' => 6],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['is_active' => true],
            );
        }
    }
}
