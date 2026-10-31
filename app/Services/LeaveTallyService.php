<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DayPart;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Builds the tally sheet: the month cells and the yearly totals.
 *
 * This is the "one source of truth" of business rule 6. The screen, the PDF and
 * the Excel export all render what this returns, so a cell can never read
 * "23–25 MFL" on screen and "23, 24, 25 MFL" on paper.
 *
 * Rule 5 is the formatting contract:
 *   - consecutive days of the same leave type compress — 23, 24, 25 → "23–25"
 *   - separate days are comma-separated — "4, 9"
 *   - half days carry AM or PM and never merge into a range — "13 PM"
 *
 * Reads are deliberately flat: one query for every leave record belonging to
 * the page of employees being shown, then grouped in PHP. A page of 20
 * employees costs two queries no matter how much leave they took.
 *
 * @phpstan-type TallyEntryDay array{id: int, date: string, day: int, dayText: string, dayPart: string, remarks: string|null}
 * @phpstan-type TallyEntry array{leaveTypeId: int, code: string, color: string, dayFigures: string, text: string, days: float, daysText: string, records: list<TallyEntryDay>}
 * @phpstan-type TallyMonth array{month: int, entries: list<TallyEntry>, days: float, daysText: string}
 * @phpstan-type TallyRow array{months: list<TallyMonth>, totals: array<int, float>, totalsText: array<int, string>, grandTotal: float, grandTotalText: string}
 */
class LeaveTallyService
{
    /** Consecutive whole days are written as a range from this length up. */
    private const MIN_RANGE_LENGTH = 2;

    /** En dash, the way a date range is set in print — not a hyphen. */
    private const RANGE_SEPARATOR = '–';

    /**
     * The tally for a page of employees, keyed by employee id.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, TallyRow>
     */
    public function forEmployees(array $employeeIds, int $year): array
    {
        $types = $this->leaveTypes();
        $records = $this->records($employeeIds, $year);

        $tally = [];

        foreach ($employeeIds as $employeeId) {
            $tally[$employeeId] = $this->buildRow($records->get($employeeId) ?? new EloquentCollection, $types);
        }

        return $tally;
    }

    /**
     * The leave types the tally has columns and chip colours for, in HR's order.
     *
     * Inactive types are included: a type retired halfway through the year
     * still has days recorded against it, and dropping the column would make
     * the grand total stop adding up (business rule 8).
     *
     * @return EloquentCollection<int, LeaveType>
     */
    public function leaveTypes(): EloquentCollection
    {
        return LeaveType::query()->ordered()->get(['id', 'code', 'name', 'color']);
    }

    /**
     * Every leave record for these employees in this year, grouped by employee.
     *
     * whereBetween on leave_date, never YEAR(leave_date), so the
     * (employee_id, leave_date) index is used.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, EloquentCollection<int, LeaveRecord>>
     */
    private function records(array $employeeIds, int $year)
    {
        if ($employeeIds === []) {
            return collect();
        }

        return LeaveRecord::query()
            ->forEmployees($employeeIds)
            ->forYear($year)
            ->orderBy('leave_date')
            ->get(['id', 'employee_id', 'leave_type_id', 'leave_date', 'day_part', 'remarks'])
            ->groupBy('employee_id');
    }

    /**
     * One employee's twelve months and their yearly totals.
     *
     * @param  EloquentCollection<int, LeaveRecord>  $records
     * @param  EloquentCollection<int, LeaveType>  $types
     * @return TallyRow
     */
    private function buildRow(EloquentCollection $records, EloquentCollection $types): array
    {
        $totals = [];

        foreach ($types as $type) {
            $totals[$type->id] = 0.0;
        }

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $inMonth = $records->filter(
                static fn (LeaveRecord $record): bool => (int) $record->leave_date->format('n') === $month
            );

            $entries = [];
            $monthDays = 0.0;

            // Ordered by the leave types, not by the records, so a month's
            // chips always appear in the same order as the totals columns.
            foreach ($types as $type) {
                $ofType = $inMonth->filter(
                    static fn (LeaveRecord $record): bool => $record->leave_type_id === $type->id
                )->sortBy('leave_date')->values();

                if ($ofType->isEmpty()) {
                    continue;
                }

                $days = $this->sumDays($ofType);
                $monthDays += $days;
                $totals[$type->id] = ($totals[$type->id] ?? 0.0) + $days;

                $figures = $this->dayFigures($ofType);

                $entries[] = [
                    'leaveTypeId' => $type->id,
                    'code' => $type->code,
                    'color' => $type->color,

                    // The day figures and the whole cell text both come from
                    // here: the chip sets the figures in mono and the code
                    // beside them, while `text` is the one string the PDF and
                    // the Excel export write.
                    'dayFigures' => $figures,
                    'text' => trim($figures.' '.$type->code),
                    'days' => $days,
                    'daysText' => $this->formatDays($days),

                    // The individual days behind the chip, so clicking it can
                    // offer "edit" and "remove" per day without a second
                    // request. It is the same data this method already read.
                    'records' => $this->entryDays($ofType),
                ];
            }

            $months[] = [
                'month' => $month,
                'entries' => $entries,
                'days' => $monthDays,
                'daysText' => $this->formatDays($monthDays),
            ];
        }

        $grandTotal = array_sum($totals);

        return [
            'months' => $months,
            'totals' => $totals,
            'totalsText' => array_map(fn (float $days): string => $this->formatDays($days), $totals),
            'grandTotal' => $grandTotal,
            'grandTotalText' => $this->formatDays($grandTotal),
        ];
    }

    /**
     * One chip's days, each written the way the cell writes it ("13 PM"), so
     * the edit list and the chip never disagree about a half day.
     *
     * @param  EloquentCollection<int, LeaveRecord>  $records  one type, one month, date order
     * @return list<TallyEntryDay>
     */
    private function entryDays(EloquentCollection $records): array
    {
        return array_values($records->map(function (LeaveRecord $record): array {
            $day = (int) $record->leave_date->format('j');

            return [
                'id' => $record->id,
                'date' => $record->leave_date->format('Y-m-d'),
                'day' => $day,
                'dayText' => $record->day_part === DayPart::Whole
                    ? (string) $day
                    : $day.' '.$record->day_part->tallySuffix(),
                'dayPart' => $record->day_part->value,
                'remarks' => $record->remarks,
            ];
        })->all());
    }

    /**
     * The day figures of one month cell, e.g. "23–25" or "3, 6–7" or "13 PM".
     *
     * @param  EloquentCollection<int, LeaveRecord>  $records  one type, one month, date order
     */
    private function dayFigures(EloquentCollection $records): string
    {
        return implode(', ', $this->dayTokens($records));
    }

    /**
     * The day figures of a cell, before the leave code is appended.
     *
     * Whole days that run consecutively collapse into one range token; a half
     * day is always its own token and never joins a range, because "23–25 PM"
     * would not say which of the three afternoons was taken.
     *
     * @param  EloquentCollection<int, LeaveRecord>  $records  date order
     * @return list<string>
     */
    private function dayTokens(EloquentCollection $records): array
    {
        $tokens = [];

        /** @var list<int> $run */
        $run = [];

        $flush = function () use (&$run, &$tokens): void {
            if ($run === []) {
                return;
            }

            $first = $run[0];
            $last = $run[count($run) - 1];

            $tokens[] = count($run) >= self::MIN_RANGE_LENGTH
                ? $first.self::RANGE_SEPARATOR.$last
                : (string) $first;

            $run = [];
        };

        foreach ($records as $record) {
            $day = (int) $record->leave_date->format('j');

            if ($record->day_part !== DayPart::Whole) {
                // A half day interrupts any run in progress: 23, 24 AM, 25
                // must not be read as 23–25.
                $flush();
                $tokens[] = $day.' '.$record->day_part->tallySuffix();

                continue;
            }

            if ($run !== [] && $day === $run[count($run) - 1] + 1) {
                $run[] = $day;

                continue;
            }

            $flush();
            $run[] = $day;
        }

        $flush();

        return $tokens;
    }

    /**
     * @param  EloquentCollection<int, LeaveRecord>  $records
     */
    private function sumDays(EloquentCollection $records): float
    {
        return round(
            $records->sum(static fn (LeaveRecord $record): float => $record->days()),
            1,
        );
    }

    /**
     * Totals read as "12" and "12.5", never "12.0" — the way they are written
     * on the paper sheet.
     */
    public function formatDays(float $days): string
    {
        if ($days === 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    }
}
