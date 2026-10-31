<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which part of a working day a leave record covers.
 *
 * Mirrored by a CHECK constraint on leave_records.day_part.
 */
enum DayPart: string
{
    case Whole = 'WHOLE';
    case Am = 'AM';
    case Pm = 'PM';

    /**
     * Leave days this part consumes. Half-days count as 0.5 (business rule 3).
     */
    public function days(): float
    {
        return $this === self::Whole ? 1.0 : 0.5;
    }

    public function isHalfDay(): bool
    {
        return $this !== self::Whole;
    }

    /**
     * Suffix used in tally cell text: "13 PM CTO", but plain "9 SL" for a whole day.
     */
    public function tallySuffix(): string
    {
        return $this === self::Whole ? '' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole day',
            self::Am => 'Morning (AM)',
            self::Pm => 'Afternoon (PM)',
        };
    }

    /**
     * Two records can share a date only if they are the opposite halves of it
     * (business rule 4). Used by LeaveEncodingService and mirrored by the
     * partial unique index on leave_records.
     */
    public function conflictsWith(self $other): bool
    {
        return $this === self::Whole
            || $other === self::Whole
            || $this === $other;
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
