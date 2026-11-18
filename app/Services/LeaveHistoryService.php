<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRecord;
use Illuminate\Support\Carbon;

/**
 * One employee's leave across every year on file (PROJECT_CONTEXT 5.6).
 *
 * The month cells and totals for each year come from LeaveTallyService, not
 * from here, so the history and the tally sheet can never disagree (business
 * rule 6). This class adds only what the tally does not show: the years an
 * employee has leave in, and each day with its remarks and who encoded it.
 *
 * @phpstan-import-type TallyRow from LeaveTallyService
 *
 * @phpstan-type HistoryDay array{id: int, date: string, leaveTypeId: int, code: string, name: string, color: string, dayPart: string, dayPartLabel: string, remarks: string|null, encodedBy: string|null, encodedAt: string|null}
 * @phpstan-type HistoryTotal array{leaveTypeId: int, code: string, name: string, color: string, days: float, daysText: string}
 * @phpstan-type HistoryMonth array{month: int, entries: list<array{code: string, color: string, dayFigures: string, text: string}>, daysText: string}
 * @phpstan-type HistoryYear array{year: int, grandTotal: float, grandTotalText: string, totals: list<HistoryTotal>, months: list<HistoryMonth>, days: list<HistoryDay>}
 */
class LeaveHistoryService
{
    public function __construct(private readonly LeaveTallyService $tally) {}

    /**
     * Every year with leave on file, newest first.
     *
     * @return array{years: list<HistoryYear>, totalDays: float, totalDaysText: string, firstYear: int|null}
     */
    public function forEmployee(Employee $employee): array
    {
        $records = LeaveRecord::query()
            ->where('employee_id', $employee->id)
            ->with(['leaveType:id,code,name,color', 'encoder:id,name'])
            ->orderBy('leave_date')
            ->orderByRaw("case day_part when 'AM' then 0 when 'PM' then 1 else 2 end")
            ->get();

        $byYear = $records->groupBy(static fn (LeaveRecord $record): int => (int) $record->leave_date->format('Y'))
            ->sortKeysDesc();

        $types = $this->tally->leaveTypes()->keyBy('id');

        $years = [];
        $totalDays = 0.0;

        foreach ($byYear as $year => $ofYear) {
            /** @var TallyRow $row */
            $row = $this->tally->forEmployees([$employee->id], (int) $year)[$employee->id];

            $totals = [];

            foreach ($row['totals'] as $leaveTypeId => $days) {
                $type = $types->get($leaveTypeId);

                if ($days <= 0 || $type === null) {
                    continue;
                }

                $totals[] = [
                    'leaveTypeId' => (int) $leaveTypeId,
                    'code' => $type->code,
                    'name' => $type->name,
                    'color' => $type->color,
                    'days' => $days,
                    'daysText' => $row['totalsText'][$leaveTypeId] ?? $this->tally->formatDays($days),
                ];
            }

            $totalDays += $row['grandTotal'];

            $years[] = [
                'year' => (int) $year,
                'grandTotal' => $row['grandTotal'],
                'grandTotalText' => $row['grandTotalText'],
                'totals' => $totals,
                'months' => array_map(static fn (array $month): array => [
                    'month' => $month['month'],
                    'entries' => array_map(static fn (array $entry): array => [
                        'code' => $entry['code'],
                        'color' => $entry['color'],
                        'dayFigures' => $entry['dayFigures'],
                        'text' => $entry['text'],
                    ], $month['entries']),
                    'daysText' => $month['daysText'],
                ], $row['months']),
                'days' => array_values($ofYear->map(static fn (LeaveRecord $record): array => [
                    'id' => (int) $record->id,
                    'date' => $record->leave_date->format('Y-m-d'),
                    'leaveTypeId' => (int) $record->leave_type_id,
                    'code' => $record->leaveType->code,
                    'name' => $record->leaveType->name,
                    'color' => $record->leaveType->color,
                    'dayPart' => $record->day_part->value,
                    'dayPartLabel' => $record->day_part->label(),
                    'remarks' => $record->remarks,
                    'encodedBy' => $record->encoder?->name,
                    'encodedAt' => $record->created_at instanceof Carbon ? $record->created_at->format('Y-m-d') : null,
                ])->all()),
            ];
        }

        $firstYear = $byYear->keys()->min();

        return [
            'years' => $years,
            'totalDays' => round($totalDays, 1),
            'totalDaysText' => $this->tally->formatDays(round($totalDays, 1)),
            'firstYear' => is_int($firstYear) ? $firstYear : null,
        ];
    }
}
