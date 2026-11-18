<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The tally sheet as it prints: one sheet per office (PROJECT_CONTEXT 5.7).
 *
 * Rows come from TallyFilters and every cell's text from LeaveTallyService,
 * so the paper reads exactly like the screen (business rule 6). This class
 * only arranges them: groups by office, numbers the rows, and picks which
 * leave type totals to print.
 *
 * @phpstan-type PrintRow array{no: int, lastName: string, firstName: string, middleName: string, position: string, salaryGrade: string, rate: string, months: list<list<string>>, totals: array<int, string>, grandTotal: string}
 * @phpstan-type PrintSheet array{office: string, rows: list<PrintRow>, typeColumns: list<array{id: int, code: string}>}
 */
class TallySheetPrint
{
    /** Tally rows are built this many employees at a time. */
    private const CHUNK = 250;

    public function __construct(private readonly LeaveTallyService $tally) {}

    /**
     * @return list<PrintSheet>
     */
    public function sheets(TallyFilters $filters, bool $showRate): array
    {
        $types = $this->tally->leaveTypes();
        $employees = $filters->employees()->get();

        $sheets = [];

        foreach ($this->groups($filters, $employees) as $office => $members) {
            $sheets[] = $this->sheet($office, $members, $filters->year, $types, $showRate);
        }

        return $sheets;
    }

    /**
     * Office name => its employees, offices A–Z, "No office" last. One office
     * selected gives that office alone, even when nobody matches, so the
     * printout still says which office it is for.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @return array<string, list<Employee>>
     */
    private function groups(TallyFilters $filters, EloquentCollection $employees): array
    {
        if ($filters->officeId > 0) {
            $name = Office::query()->whereKey($filters->officeId)->value('name');

            return [is_string($name) ? $name : 'Unknown office' => array_values($employees->all())];
        }

        if ($employees->isEmpty()) {
            return ['All offices' => []];
        }

        $groups = [];

        foreach ($employees as $employee) {
            $groups[$employee->office->name ?? ''][] = $employee;
        }

        uksort($groups, static function (string $a, string $b): int {
            if ($a === '' || $b === '') {
                return $a === '' ? 1 : -1;
            }

            return strnatcasecmp($a, $b);
        });

        $named = [];

        foreach ($groups as $name => $members) {
            $named[$name === '' ? 'No office' : $name] = $members;
        }

        return $named;
    }

    /**
     * @param  list<Employee>  $employees
     * @param  EloquentCollection<int, LeaveType>  $types
     * @return PrintSheet
     */
    private function sheet(string $office, array $employees, int $year, EloquentCollection $types, bool $showRate): array
    {
        $tally = [];

        foreach (array_chunk($employees, self::CHUNK) as $chunk) {
            $tally += $this->tally->forEmployees(
                array_map(static fn (Employee $employee): int => $employee->id, $chunk),
                $year,
            );
        }

        // Only the leave types with days on this office's sheet get a column.
        $used = [];

        foreach ($tally as $row) {
            foreach ($row['totals'] as $typeId => $days) {
                if ($days > 0) {
                    $used[$typeId] = true;
                }
            }
        }

        $typeColumns = array_values($types
            ->filter(static fn (LeaveType $type): bool => isset($used[$type->id]))
            ->map(static fn (LeaveType $type): array => ['id' => (int) $type->id, 'code' => $type->code])
            ->all());

        $rows = [];

        foreach ($employees as $index => $employee) {
            $row = $tally[$employee->id];

            $rows[] = [
                'no' => $index + 1,
                'lastName' => $employee->last_name,
                // The suffix is written inside the first name on the paper sheet.
                'firstName' => trim($employee->first_name.' '.($employee->suffix ?? '')),
                'middleName' => $employee->middle_name ?? '',
                'position' => $employee->position_title,
                'salaryGrade' => $employee->salary_grade !== null ? (string) $employee->salary_grade : '',
                'rate' => $showRate && $employee->monthly_rate !== null ? number_format((float) $employee->monthly_rate, 2) : '',
                'months' => array_map(
                    static fn (array $month): array => array_map(static fn (array $entry): string => $entry['text'], $month['entries']),
                    $row['months'],
                ),
                'totals' => array_map(
                    static fn (array $column): string => $row['totalsText'][$column['id']] ?? '',
                    array_combine(array_column($typeColumns, 'id'), $typeColumns),
                ),
                'grandTotal' => $row['grandTotalText'],
            ];
        }

        return ['office' => $office, 'rows' => $rows, 'typeColumns' => $typeColumns];
    }
}
