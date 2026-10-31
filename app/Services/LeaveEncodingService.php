<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns "Dela Cruz was on sick leave from the 23rd to the 25th" into rows.
 *
 * Business rules 1, 2, 3, 4 and 10 are all enforced here:
 *
 *  1. One row per leave day, so counting is COUNT/SUM and a future credits
 *     module gets a clean "leave taken" input.
 *  2. Weekends and holidays are skipped when a range is expanded.
 *  3. AM or PM counts as half a day.
 *  4. The same date cannot hold a whole day plus anything else, or two AMs.
 * 10. A range crossing 31 December needs no special handling: each day is its
 *     own row carrying its own date, so the days simply land in the year they
 *     belong to, and each year's tally reads correctly.
 *
 * Conflicts surface as a ValidationException rather than a custom exception
 * the controller would have to translate. A double-encoded date is something
 * the encoder has to fix in the form in front of them, so it belongs on the
 * form field — and the controller stays thin.
 */
class LeaveEncodingService
{
    /**
     * The longest range that may be encoded in one go.
     *
     * Leave is approved in days or weeks, so a range longer than a year is a
     * typo in the year field — the kind that would otherwise write thousands
     * of rows before anyone noticed.
     */
    public const MAX_RANGE_DAYS = 366;

    public function __construct(private readonly HolidayCalendar $calendar) {}

    /**
     * What saving this range would actually do, without saving it.
     *
     * Feeds the preview in the encoding panel: HR sees the exact dates, the
     * weekends and holidays being skipped and any date already encoded, before
     * committing. Deliberately does not lock or write anything.
     *
     * @return array{
     *     dates: list<array{date: string, day: int, month: int, weekday: string}>,
     *     skipped: list<array{date: string, day: int, reason: string}>,
     *     conflicts: list<array{date: string, reason: string}>,
     *     days: float
     * }
     */
    public function preview(Employee $employee, Carbon $from, Carbon $to, DayPart $dayPart, ?int $ignoreRecordId = null): array
    {
        $working = $this->workingDaysBetween($from, $to);
        $skipped = $this->nonWorkingDaysBetween($from, $to);

        $conflicts = [];

        if ($working !== []) {
            foreach ($this->conflictingRecords($employee, $working, $dayPart, $ignoreRecordId) as $record) {
                $conflicts[] = [
                    'date' => $record->leave_date->format('Y-m-d'),
                    'reason' => $this->describeConflict($record),
                ];
            }
        }

        return [
            'dates' => array_map(
                static fn (Carbon $date): array => [
                    'date' => $date->format('Y-m-d'),
                    'day' => (int) $date->format('j'),
                    'month' => (int) $date->format('n'),
                    'weekday' => $date->format('D'),
                ],
                $working,
            ),
            'skipped' => $skipped,
            'conflicts' => $conflicts,
            'days' => round(count($working) * $dayPart->days(), 1),
        ];
    }

    /**
     * Saves one leave record per working day in the range.
     *
     * @param  array{employee_id: int, leave_type_id: int, date_from: string, date_to: string, day_part: string, remarks?: string|null}  $data
     * @return list<LeaveRecord> the rows created, in date order
     */
    public function store(array $data, ?int $encodedBy): array
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $dayPart = DayPart::from($data['day_part']);
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();

        $dates = $this->workingDaysBetween($from, $to);

        if ($dates === []) {
            throw ValidationException::withMessages([
                'date_from' => 'Every day in that range is a weekend or a holiday, so there is nothing to record.',
            ]);
        }

        return DB::transaction(function () use ($employee, $dates, $dayPart, $data, $encodedBy): array {
            // Layer 2 of the double-encoding guard. The partial unique index on
            // leave_records cannot express "a whole day excludes a half day",
            // so that overlap is checked here — and the rows are locked first,
            // because two encoders saving the same employee at the same moment
            // would otherwise both read "no conflict" and both insert.
            $this->assertNoConflicts($employee, $dates, $dayPart);

            $created = [];

            foreach ($dates as $date) {
                $created[] = LeaveRecord::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $data['leave_type_id'],
                    'leave_date' => $date->format('Y-m-d'),
                    'day_part' => $dayPart->value,
                    'remarks' => $this->nullIfBlank($data['remarks'] ?? null),
                    'encoded_by' => $encodedBy,
                ]);
            }

            return $created;
        });
    }

    /**
     * Changes one already-encoded day.
     *
     * The date may move, which is how an encoder fixes a day typed wrong,
     * so the conflict check runs again — ignoring this record, or moving a
     * record onto its own date would conflict with itself.
     *
     * @param  array{leave_type_id: int, leave_date: string, day_part: string, remarks?: string|null}  $data
     */
    public function update(LeaveRecord $record, array $data): LeaveRecord
    {
        $dayPart = DayPart::from($data['day_part']);
        $date = Carbon::parse($data['leave_date'])->startOfDay();

        // Explicit, because lazy loading is disabled outside production: the
        // conflict check needs the owner of the record being moved.
        $record->loadMissing('employee');

        if ($this->calendar->isNonWorkingDay($date)) {
            throw ValidationException::withMessages([
                'leave_date' => sprintf(
                    '%s is %s, so no leave is charged against it.',
                    $date->format('j F Y'),
                    $this->calendar->nameFor($date) ?? 'a weekend',
                ),
            ]);
        }

        return DB::transaction(function () use ($record, $date, $dayPart, $data): LeaveRecord {
            $this->assertNoConflicts($record->employee, [$date], $dayPart, $record->id);

            $record->update([
                'leave_type_id' => $data['leave_type_id'],
                'leave_date' => $date->format('Y-m-d'),
                'day_part' => $dayPart->value,
                'remarks' => $this->nullIfBlank($data['remarks'] ?? null),
            ]);

            return $record;
        });
    }

    /**
     * Soft delete, so a day removed by mistake is recoverable and the activity
     * log keeps a record of it (business rule 9).
     */
    public function delete(LeaveRecord $record): void
    {
        $record->delete();
    }

    /**
     * Every working day in the range, inclusive of both ends.
     *
     * @return list<Carbon>
     */
    public function workingDaysBetween(Carbon $from, Carbon $to): array
    {
        $dates = [];

        foreach ($this->eachDay($from, $to) as $date) {
            if (! $this->calendar->isNonWorkingDay($date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * The days the range passes over but does not charge leave against, with
     * the reason, so the encoder can see a holiday was recognised rather than
     * wonder where a date went.
     *
     * @return list<array{date: string, day: int, reason: string}>
     */
    private function nonWorkingDaysBetween(Carbon $from, Carbon $to): array
    {
        $skipped = [];

        foreach ($this->eachDay($from, $to) as $date) {
            $holiday = $this->calendar->nameFor($date);

            if ($holiday !== null) {
                $skipped[] = [
                    'date' => $date->format('Y-m-d'),
                    'day' => (int) $date->format('j'),
                    'reason' => $holiday,
                ];

                continue;
            }

            if ($date->isWeekend()) {
                $skipped[] = [
                    'date' => $date->format('Y-m-d'),
                    'day' => (int) $date->format('j'),
                    'reason' => $date->format('l'),
                ];
            }
        }

        return $skipped;
    }

    /**
     * @return list<Carbon>
     */
    private function eachDay(Carbon $from, Carbon $to): array
    {
        if ($to->lessThan($from)) {
            return [];
        }

        if ($from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => 'That range covers more than a year. Check the dates and encode one leave period at a time.',
            ]);
        }

        $days = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  list<Carbon>  $dates
     */
    private function assertNoConflicts(Employee $employee, array $dates, DayPart $dayPart, ?int $ignoreRecordId = null): void
    {
        $clash = $this->conflictingRecords($employee, $dates, $dayPart, $ignoreRecordId)->first();

        if ($clash === null) {
            return;
        }

        // The message names the date, because "already encoded" on a two-week
        // range tells the encoder nothing about which day to look at.
        throw ValidationException::withMessages([
            'date_from' => sprintf(
                '%s already has %s for %s. Remove or edit that entry first.',
                $clash->leave_date->format('j F Y'),
                $this->describeConflict($clash),
                $employee->first_name,
            ),
        ]);
    }

    /**
     * Existing records that cannot coexist with $dayPart on any of $dates.
     *
     * @param  list<Carbon>  $dates
     * @return Collection<int, LeaveRecord>
     */
    private function conflictingRecords(Employee $employee, array $dates, DayPart $dayPart, ?int $ignoreRecordId = null): Collection
    {
        $query = LeaveRecord::query()
            ->with('leaveType:id,code')
            ->where('employee_id', $employee->id)
            ->whereIn('leave_date', array_map(
                static fn (Carbon $date): string => $date->format('Y-m-d'),
                $dates,
            ))
            ->orderBy('leave_date');

        if ($ignoreRecordId !== null) {
            $query->whereKeyNot($ignoreRecordId);
        }

        // Locking only matters inside the write transaction, but asking for the
        // lock here keeps one definition of "what conflicts" instead of two
        // that could drift apart.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return $query->get()->filter(
            static fn (LeaveRecord $record): bool => $record->day_part->conflictsWith($dayPart),
        );
    }

    private function describeConflict(LeaveRecord $record): string
    {
        $code = $record->leaveType->code;

        return $record->day_part === DayPart::Whole
            ? sprintf('a whole day of %s', $code)
            : sprintf('%s %s', $code, $record->day_part->value);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Leave types an encoder may choose from, ordered the way HR listed them.
     *
     * @return list<array{value: int, label: string, code: string, color: string}>
     */
    public function leaveTypeOptions(): array
    {
        return array_values(
            LeaveType::query()
                ->active()
                ->ordered()
                ->get(['id', 'code', 'name', 'color'])
                ->map(static fn (LeaveType $type): array => [
                    'value' => $type->id,
                    'label' => sprintf('%s — %s', $type->code, $type->name),
                    'code' => $type->code,
                    'color' => $type->color,
                ])
                ->all()
        );
    }
}
