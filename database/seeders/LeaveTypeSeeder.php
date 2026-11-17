<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/** The active leave categories available on the current tally sheet. */
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
            ['code' => 'MFL', 'name' => 'Mandatory/Forced Leave', 'color' => 'violet', 'sort_order' => 2],
            ['code' => 'SL', 'name' => 'Sick Leave', 'color' => 'amber', 'sort_order' => 3],
            ['code' => 'ML', 'name' => 'Maternity Leave', 'color' => 'rose', 'sort_order' => 4],
            ['code' => 'PL', 'name' => 'Paternity Leave', 'color' => 'amber', 'sort_order' => 5],
            ['code' => 'SPL', 'name' => 'Special Privilege Leave', 'color' => 'teal', 'sort_order' => 6],
            ['code' => 'SOPL', 'name' => 'Solo Parent Leave', 'color' => 'teal', 'sort_order' => 7],
            ['code' => 'STL', 'name' => 'Study Leave', 'color' => 'violet', 'sort_order' => 8],
            ['code' => 'VAWC', 'name' => '10-Day VAWC Leave', 'color' => 'rose', 'sort_order' => 9],
            ['code' => 'RP', 'name' => 'Rehabilitation Privilege', 'color' => 'slate', 'sort_order' => 10],
            ['code' => 'SLBW', 'name' => 'Special Leave Benefits for Women', 'color' => 'emerald', 'sort_order' => 11],
            ['code' => 'SEL', 'name' => 'Special Emergency (Calamity) Leave', 'color' => 'amber', 'sort_order' => 12],
            ['code' => 'AL', 'name' => 'Adoption Leave', 'color' => 'sky', 'sort_order' => 13],
            ['code' => 'CTO', 'name' => 'Compensatory Time-Off', 'color' => 'slate', 'sort_order' => 14],
            ['code' => 'WL', 'name' => 'Wellness Leave', 'color' => 'emerald', 'sort_order' => 15],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['is_active' => true],
            );
        }
    }
}
