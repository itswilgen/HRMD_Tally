<?php

declare(strict_types=1);

use App\Enums\DayPart;

it('counts a whole day as one day and a half day as 0.5', function (): void {
    expect(DayPart::Whole->days())->toBe(1.0)
        ->and(DayPart::Am->days())->toBe(0.5)
        ->and(DayPart::Pm->days())->toBe(0.5);
});

it('knows which parts are half days', function (): void {
    expect(DayPart::Whole->isHalfDay())->toBeFalse()
        ->and(DayPart::Am->isHalfDay())->toBeTrue()
        ->and(DayPart::Pm->isHalfDay())->toBeTrue();
});

it('suffixes tally text only for half days', function (): void {
    // "9 SL" for a whole day, "13 PM CTO" for an afternoon.
    expect(DayPart::Whole->tallySuffix())->toBe('')
        ->and(DayPart::Am->tallySuffix())->toBe('AM')
        ->and(DayPart::Pm->tallySuffix())->toBe('PM');
});

describe('conflictsWith', function (): void {
    it('treats a whole day as conflicting with anything on the same date', function (): void {
        expect(DayPart::Whole->conflictsWith(DayPart::Whole))->toBeTrue()
            ->and(DayPart::Whole->conflictsWith(DayPart::Am))->toBeTrue()
            ->and(DayPart::Whole->conflictsWith(DayPart::Pm))->toBeTrue();
    });

    it('treats any half day as conflicting with a whole day', function (): void {
        expect(DayPart::Am->conflictsWith(DayPart::Whole))->toBeTrue()
            ->and(DayPart::Pm->conflictsWith(DayPart::Whole))->toBeTrue();
    });

    it('rejects two of the same half day', function (): void {
        expect(DayPart::Am->conflictsWith(DayPart::Am))->toBeTrue()
            ->and(DayPart::Pm->conflictsWith(DayPart::Pm))->toBeTrue();
    });

    it('allows opposite halves of the same date', function (): void {
        expect(DayPart::Am->conflictsWith(DayPart::Pm))->toBeFalse()
            ->and(DayPart::Pm->conflictsWith(DayPart::Am))->toBeFalse();
    });
});
