<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a holiday is a holiday, which decides whether it can be entered once or
 * must be entered every year (answered by HR 2026-10-31, section 5.3).
 *
 * All three kinds are skipped identically when a leave range is expanded. The
 * distinction exists because of how the dates are *maintained*, not how they
 * are counted: only RegularFixed can be entered once and trusted forever.
 */
enum HolidayKind: string
{
    /** Jan 1, Apr 9, May 1, Jun 12, Nov 30, Dec 25, Dec 30 — same date every year. */
    case RegularFixed = 'REGULAR_FIXED';

    /** Holy Week, Eid'l Fitr, Eid'l Adha, National Heroes Day — moves yearly. */
    case RegularMovable = 'REGULAR_MOVABLE';

    /** Set each year by presidential proclamation. */
    case SpecialNonWorking = 'SPECIAL_NON_WORKING';

    public function label(): string
    {
        return match ($this) {
            self::RegularFixed => 'Regular holiday (fixed date)',
            self::RegularMovable => 'Regular holiday (movable)',
            self::SpecialNonWorking => 'Special non-working day',
        };
    }

    /**
     * Whether this kind falls on the same month and day in every year, and so
     * is matched on month/day rather than needing one row per year.
     */
    public function recursYearly(): bool
    {
        return $this === self::RegularFixed;
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::RegularFixed => 'Regular, fixed',
            self::RegularMovable => 'Regular, movable',
            self::SpecialNonWorking => 'Special non-working',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
