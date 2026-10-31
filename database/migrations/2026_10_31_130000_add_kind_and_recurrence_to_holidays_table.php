<?php

declare(strict_types=1);

use App\Enums\HolidayKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the holidays table to match section 7 of the project doc.
 *
 * The table was created in Phase 1, before HR answered which holidays recur
 * (2026-10-31). Without `recurs_yearly` a leave range encoded for 2027 would
 * happily include 1 January, because only 2026 rows were ever seeded — so this
 * is a correctness dependency of leave encoding, not cosmetic.
 */
return new class extends Migration
{
    /**
     * The seven regular holidays that fall on the same date every year, as
     * month-day. Used only to backfill the rows seeded before this column
     * existed; from here on the kind is chosen by whoever enters the holiday.
     */
    private const FIXED_MONTH_DAYS = ['01-01', '04-09', '05-01', '06-12', '11-30', '12-25', '12-30'];

    /**
     * Regular holidays that move every year, so they cannot be matched on
     * month and day. Matched by name because that is all the Phase 1 seeder
     * recorded about them.
     */
    private const MOVABLE_NAMES = ['Maundy Thursday', 'Good Friday', 'National Heroes Day'];

    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table): void {
            $table->string('kind', 24)->nullable()->after('name');

            // Derived from `kind` on every write, but stored and queried
            // separately: resolving a year's non-working days asks one plain
            // boolean question, and section 7 of the doc specifies the column.
            $table->boolean('recurs_yearly')->default(false)->after('kind');
        });

        // Everything seeded so far is a 2026 national holiday, so the kind can
        // be inferred. Anything unrecognised becomes a special non-working day,
        // which is the kind that is re-entered yearly — the safe default, since
        // it never silently recurs into a year HR has not checked.
        DB::table('holidays')
            ->whereIn('name', self::MOVABLE_NAMES)
            ->update(['kind' => HolidayKind::RegularMovable->value]);

        DB::table('holidays')
            ->whereNull('kind')
            ->update(['kind' => HolidayKind::SpecialNonWorking->value]);

        foreach (self::FIXED_MONTH_DAYS as $monthDay) {
            DB::table('holidays')
                ->whereRaw("to_char(date, 'MM-DD') = ?", [$monthDay])
                ->update([
                    'kind' => HolidayKind::RegularFixed->value,
                    'recurs_yearly' => true,
                ]);
        }

        Schema::table('holidays', function (Blueprint $table): void {
            $table->string('kind', 24)->nullable(false)->change();
        });

        // A genuinely closed set, enforced in the database as well as by the
        // HolidayKind enum — the same treatment leave_records.day_part gets.
        $allowed = implode("', '", array_column(HolidayKind::cases(), 'value'));

        DB::statement(
            "ALTER TABLE holidays ADD CONSTRAINT holidays_kind_check
             CHECK (kind IN ('{$allowed}'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE holidays DROP CONSTRAINT IF EXISTS holidays_kind_check');

        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'recurs_yearly']);
        });
    }
};
