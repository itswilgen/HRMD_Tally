<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveType;

/**
 * One year's credits as export rows (PROJECT_CONTEXT 6.1, rule 22).
 *
 * Every figure comes from LeaveCreditService::forEmployees, the same call the
 * credits page makes, so the workbook and the screen agree; "taken" in turn is
 * the tally's count (rule 15). This class only picks the columns and lays the
 * figures out: granted, taken, left under each leave code.
 *
 * @phpstan-type ExportType array{id: int, code: string, name: string}
 * @phpstan-type ExportCell array{granted: float|null, taken: float|null, left: float|null}
 * @phpstan-type ExportRow array{no: int, employeeNo: string, lastName: string, firstName: string, middleName: string, office: string, position: string, cells: array<int, ExportCell>, total: ExportCell}
 */
class LeaveCreditExport
{
    /** Credit rows are built this many employees at a time. */
    private const CHUNK = 250;

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly LeaveTallyService $tally,
    ) {}

    /**
     * @return array{types: list<ExportType>, rows: list<ExportRow>}
     */
    public function build(int $year, string $search = '', string $letter = '', int $officeId = 0): array
    {
        $types = $this->tally->leaveTypes();
        $typeIds = array_values($types->map(static fn (LeaveType $type): int => (int) $type->id)->all());
        $employees = $this->credits->filteredEmployees($year, $search, $letter, $officeId)->get();

        $figures = [];

        foreach ($employees->chunk(self::CHUNK) as $chunk) {
            $figures += $this->credits->forEmployees(
                array_values($chunk->map(static fn (Employee $employee): int => $employee->id)->all()),
                $year,
                $typeIds,
            );
        }

        // A leave code gets its three columns only when someone exported has
        // a figure or days taken under it this year.
        $used = [];

        foreach ($figures as $row) {
            foreach ($row['cells'] as $typeId => $cell) {
                if ($cell['credits'] !== null || $cell['used'] > 0) {
                    $used[$typeId] = true;
                }
            }
        }

        $columns = array_values($types
            ->filter(static fn (LeaveType $type): bool => isset($used[$type->id]))
            ->map(static fn (LeaveType $type): array => ['id' => (int) $type->id, 'code' => $type->code, 'name' => $type->name])
            ->all());

        $rows = [];

        foreach ($employees->values() as $index => $employee) {
            $row = $figures[$employee->id];
            $cells = [];

            foreach ($columns as $column) {
                $cell = $row['cells'][$column['id']];

                $cells[$column['id']] = [
                    // Blank and 0 are different answers (rule 14): no figure
                    // stays empty, and so does what is left of it.
                    'granted' => $cell['credits'],
                    // Taken is 0 beside a figure, blank where there is nothing.
                    'taken' => $cell['used'] > 0 || $cell['credits'] !== null ? $cell['used'] : null,
                    'left' => $cell['remaining'],
                ];
            }

            $rows[] = [
                'no' => $index + 1,
                'employeeNo' => $employee->employee_no,
                'lastName' => $employee->last_name,
                'firstName' => trim($employee->first_name.' '.($employee->suffix ?? '')),
                'middleName' => $employee->middle_name ?? '',
                'office' => $employee->office->name ?? '',
                'position' => $employee->position_title,
                'cells' => $cells,
                'total' => [
                    'granted' => $row['hasCredits'] ? $row['creditsTotal'] : null,
                    'taken' => $row['usedTotal'] > 0 || $row['hasCredits'] ? $row['usedTotal'] : null,
                    'left' => $row['hasCredits'] ? $row['remainingTotal'] : null,
                ],
            ];
        }

        return ['types' => $columns, 'rows' => $rows];
    }
}
