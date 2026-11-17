<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<array{code: string, name: string, color: string, sort_order: int}>
     */
    private const TYPES = [
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

    public function up(): void
    {
        $now = now();
        $codes = array_column(self::TYPES, 'code');

        DB::table('leave_types')
            ->whereNotIn('code', $codes)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => $now]);

        foreach (self::TYPES as $type) {
            $existing = DB::table('leave_types')->where('code', $type['code'])->first();
            $values = [
                'name' => $type['name'],
                'color' => $type['color'],
                'is_active' => true,
                'sort_order' => $type['sort_order'],
            ];

            if ($existing === null) {
                DB::table('leave_types')->insert([
                    ...$type,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $needsUpdate = $existing->name !== $type['name']
                || $existing->color !== $type['color']
                || ! (bool) $existing->is_active
                || (int) $existing->sort_order !== $type['sort_order'];

            if ($needsUpdate) {
                DB::table('leave_types')->where('id', $existing->id)->update([
                    ...$values,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep catalog rows and historical leave relationships intact.
    }
};
