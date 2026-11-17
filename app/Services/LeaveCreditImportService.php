<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\SheetReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Filling a year's leave credits from Excel.
 *
 * The file is the credits sheet the HR Admin downloaded for that year: one row
 * per employee on the year's sheet, one column per leave code, with the figures
 * already on file filled in. HR types over it and uploads it back.
 *
 * Rows are matched by **employee number**, never by name: the number is what
 * stays with one person, and two Dela Cruzes on one payroll is ordinary. The
 * NO., EMPLOYEE and OFFICE columns are there for the person reading the sheet
 * and are not read back.
 *
 * A blank cell leaves that figure as it is (business rule 20). On screen, a
 * cleared cell removes a figure; in a file, a blank is far more often a column
 * nobody got to, and an import that quietly wiped every figure HR had not
 * retyped would be the worst thing this could do. Clearing happens on screen,
 * where it can be seen.
 *
 * Like the masterlist import, the file is read twice — once to preview it and
 * once to save it — and saving is all or nothing.
 *
 * @phpstan-type Change array{code: string, from: string|null, to: string}
 * @phpstan-type PreviewRow array{line: int, employeeNo: string, name: string, changes: list<Change>, errors: list<string>}
 * @phpstan-type Preview array{rows: list<PreviewRow>, readyCount: int, unchangedCount: int, problemCount: int, figureCount: int, ignoredColumns: list<string>}
 * @phpstan-type CheckedRow array{line: int, employeeNo: string, name: string, changes: list<Change>, errors: list<string>, employeeId: int|null, days: array<int, float>}
 */
class LeaveCreditImportService
{
    /** The same ceiling as the masterlist import, which is the larger list. */
    public const MAX_ROWS = 5000;

    /** How far down the file the heading row is looked for. */
    private const HEADING_SEARCH_ROWS = 10;

    /** The columns that describe the person rather than a figure. */
    private const PERSON_COLUMNS = [
        'NO.' => 'no',
        'EMPLOYEE NO.' => 'employee_no',
        'EMPLOYEE' => 'employee',
        'OFFICE' => 'office',
    ];

    /** Other spellings of the same headings that a retyped sheet may carry. */
    private const ALIASES = [
        'EMPLOYEE NUMBER' => 'employee_no',
        'EMP NO' => 'employee_no',
        'EMP. NO.' => 'employee_no',
        'NAME' => 'employee',
    ];

    public function __construct(
        private readonly SheetReader $sheets,
        private readonly LeaveCreditService $credits,
    ) {}

    /**
     * The template's headings: who the row is, then one column per leave code
     * a figure can still be added for. Retired codes are left off — they take
     * no new figure, and their old ones are corrected on screen.
     *
     * @return list<string>
     */
    public function templateHeadings(): array
    {
        return array_values([
            ...array_keys(self::PERSON_COLUMNS),
            ...$this->activeTypes()->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all(),
        ]);
    }

    /**
     * The year's sheet as rows for the template, with the figures already on
     * file filled in, so HR edits what is there instead of starting blank.
     *
     * @return list<list<int|float|string|null>>
     */
    public function templateRows(int $year): array
    {
        $types = $this->activeTypes();

        $employees = $this->credits->employeesOnSheet($year)
            ->with('office:id,name')
            ->orderedByName()
            ->get();

        $figures = $this->figuresOnFile($employees->modelKeys(), $year);

        $rows = [];

        foreach ($employees->values() as $index => $employee) {
            $row = [$index + 1, $employee->employee_no, $employee->list_name, $employee->office->name ?? ''];

            foreach ($types as $type) {
                $row[] = $figures[$employee->id][$type->id] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * What saving this file would change.
     *
     * @return Preview
     *
     * @throws ValidationException when the file as a whole cannot be read
     */
    public function preview(string $path, int $year): array
    {
        [$rows, $ignored] = $this->checkFile($path, $year);

        $problems = 0;
        $ready = 0;
        $figures = 0;

        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                $problems++;
            } elseif ($row['changes'] !== []) {
                $ready++;
                $figures += count($row['changes']);
            }
        }

        return [
            'rows' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'employeeNo' => $row['employeeNo'],
                'name' => $row['name'],
                'changes' => $row['changes'],
                'errors' => $row['errors'],
            ], $rows),
            'readyCount' => $ready,
            'unchangedCount' => count($rows) - $ready - $problems,
            'problemCount' => $problems,
            'figureCount' => $figures,
            'ignoredColumns' => $ignored,
        ];
    }

    /**
     * Saves every change in the file, or none of them.
     *
     * @return array{employees: int, figures: int}
     *
     * @throws ValidationException when the file or any row in it has a problem
     */
    public function import(string $path, string $fileName, int $year, User $user): array
    {
        [$rows] = $this->checkFile($path, $year);

        $problems = count(array_filter($rows, static fn (array $row): bool => $row['errors'] !== []));

        if ($problems > 0) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    '%d %s still %s problems, so nothing was saved. Fix them in the file and preview it again.',
                    $problems,
                    $problems === 1 ? 'row' : 'rows',
                    $problems === 1 ? 'has' : 'have',
                ),
            ]);
        }

        $daysByEmployee = [];
        $figures = 0;

        foreach ($rows as $row) {
            if ($row['employeeId'] !== null && $row['days'] !== []) {
                $daysByEmployee[$row['employeeId']] = $row['days'];
                $figures += count($row['days']);
            }
        }

        if ($daysByEmployee === []) {
            return ['employees' => 0, 'figures' => 0];
        }

        return DB::transaction(function () use ($daysByEmployee, $figures, $year, $fileName, $user): array {
            // saveMany logs each employee's old and new figures, exactly as a
            // save from the grid does; this entry records where they came from.
            $employees = $this->credits->saveMany($daysByEmployee, $year, $user);

            activity('leave_credit')
                ->causedBy($user)
                ->event('imported')
                ->withProperties(['file' => $fileName, 'year' => $year, 'employees' => $employees, 'figures' => $figures])
                ->log(sprintf('Imported %d leave credit figures for %d from %s', $figures, $year, $fileName));

            return ['employees' => $employees, 'figures' => $figures];
        });
    }

    /**
     * Reads the file and checks every row against the database as it is now.
     *
     * @return array{0: list<CheckedRow>, 1: list<string>}
     *
     * @throws ValidationException
     */
    private function checkFile(string $path, int $year): array
    {
        $types = LeaveType::query()->ordered()->get(['id', 'code', 'name', 'is_active'])->keyBy('id');

        [$columns, $lines, $ignored] = $this->read($path, $types);

        /** @var array<int, string> $typeColumns position => leave type id as "type:id" */
        $typeColumns = array_filter($columns, static fn (string $key): bool => str_starts_with($key, 'type:'));

        $numbers = [];

        foreach ($lines as $cells) {
            $number = $this->sheets->cell($cells, $columns, 'employee_no');

            if ($number !== null) {
                $numbers[] = $number;
            }
        }

        // withTrashed so a deleted record gets a message that says so, rather
        // than one claiming the number does not exist.
        $employees = Employee::withTrashed()
            ->whereIn('employee_no', array_unique($numbers))
            ->get(['id', 'employee_no', 'last_name', 'first_name', 'middle_name', 'suffix', 'deleted_at'])
            ->keyBy('employee_no');

        $onFile = $this->figuresOnFile(
            $employees->reject(static fn (Employee $employee): bool => $employee->trashed())->modelKeys(),
            $year,
        );

        $seen = [];
        $rows = [];

        foreach ($lines as $line => $cells) {
            $errors = [];
            $changes = [];
            $days = [];

            $number = $this->sheets->cell($cells, $columns, 'employee_no') ?? '';
            /** @var Employee|null $employee */
            $employee = $number === '' ? null : $employees->get($number);
            $name = $employee->list_name ?? ($this->sheets->cell($cells, $columns, 'employee') ?? '');

            if ($number === '') {
                $errors[] = 'The EMPLOYEE NO. cell is empty. Each row is matched to an employee by that number.';
            } elseif ($employee === null) {
                $errors[] = "No employee on the masterlist has the number {$number}.";
            } elseif ($employee->trashed()) {
                $errors[] = "{$employee->list_name} ({$number}) has been deleted from the masterlist, so no credits can be set for them.";
            } elseif (isset($seen[$number])) {
                $errors[] = "{$employee->list_name} is also on row {$seen[$number]}.";
            } else {
                $seen[$number] = $line;
            }

            $usable = $employee !== null && ! $employee->trashed();

            foreach ($typeColumns as $key) {
                $type = $types->get((int) substr($key, 5));

                if (! $type instanceof LeaveType) {
                    continue;
                }

                $raw = $this->sheets->cell($cells, $columns, $key);

                if ($raw === null) {
                    continue; // blank leaves the figure as it is
                }

                $message = $this->figureProblem($raw);

                if ($message !== null) {
                    $errors[] = "{$type->code}: {$message}";

                    continue;
                }

                $value = (float) $this->decimal($raw);
                $current = $usable ? ($onFile[$employee->id][$type->id] ?? null) : null;

                if ($current !== null && abs($current - $value) < 0.05) {
                    continue;
                }

                if (! $type->is_active && $current === null) {
                    $errors[] = "{$type->code} has been retired, so a new figure cannot be added against it.";

                    continue;
                }

                $changes[] = [
                    'code' => $type->code,
                    'from' => $current === null ? null : $this->credits->figure($current),
                    'to' => $this->credits->figure($value),
                ];
                $days[$type->id] = $value;
            }

            $rows[] = [
                'line' => $line,
                'employeeNo' => $number,
                'name' => $name,
                'changes' => $changes,
                'errors' => $errors,
                'employeeId' => $usable && $errors === [] ? $employee->id : null,
                'days' => $errors === [] ? $days : [],
            ];
        }

        return [$rows, $ignored];
    }

    /**
     * The heading row's columns, the data rows under it, and the headings it
     * has that are not read.
     *
     * @param  Collection<int, LeaveType>  $types
     * @return array{0: array<int, string>, 1: array<int, list<string>>, 2: list<string>}
     *
     * @throws ValidationException
     */
    private function read(string $path, Collection $types): array
    {
        $filled = $this->sheets->rows($path, self::MAX_ROWS);
        $known = $this->knownHeadings($types);

        $headingRow = null;
        $columns = [];

        foreach (array_slice($filled, 0, self::HEADING_SEARCH_ROWS, true) as $line => $cells) {
            $found = $this->sheets->headings($cells, $known);

            if (in_array('employee_no', $found, true)) {
                $headingRow = $line;
                $columns = $found;

                break;
            }
        }

        if ($headingRow === null) {
            throw ValidationException::withMessages([
                'file' => 'The file is missing the EMPLOYEE NO. column. Download the sheet for the year and fill that in.',
            ]);
        }

        if (array_filter($columns, static fn (string $key): bool => str_starts_with($key, 'type:')) === []) {
            throw ValidationException::withMessages([
                'file' => 'The file has no leave code columns (VL, SL and so on). Download the sheet for the year and fill that in.',
            ]);
        }

        $ignored = [];

        foreach ($filled[$headingRow] as $position => $heading) {
            if ($heading !== '' && ! isset($columns[$position])) {
                $ignored[] = $heading;
            }
        }

        $lines = [];

        foreach ($filled as $line => $cells) {
            if ($line <= $headingRow) {
                continue;
            }

            if (count($lines) === self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => sprintf('The file has more than %s rows. Split it into smaller files.', number_format(self::MAX_ROWS)),
                ]);
            }

            $lines[$line] = $cells;
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'file' => 'The file has no employees in it. Keep the heading row and one employee per row under it.',
            ]);
        }

        return [$columns, $lines, $ignored];
    }

    /**
     * Heading => key, in the shape SheetReader::headings() wants. A leave
     * column answers to its code and to its name, retired ones included, so a
     * sheet from before a code was retired still reads and says why its
     * figures cannot be added.
     *
     * @param  Collection<int, LeaveType>  $types
     * @return array<string, string>
     */
    private function knownHeadings(Collection $types): array
    {
        $known = [...self::PERSON_COLUMNS, ...self::ALIASES];

        foreach ($types as $type) {
            $known[$type->code] = 'type:'.$type->id;
            $known[$type->name] = 'type:'.$type->id;
        }

        return $known;
    }

    /**
     * Why a cell is not a figure, in the words the on-screen forms use, or null
     * when it is one.
     */
    private function figureProblem(string $raw): ?string
    {
        $validator = Validator::make(
            ['days' => $this->decimal($raw)],
            ['days' => ['numeric', 'min:0', 'max:999', 'multiple_of:0.5']],
            [
                'days.numeric' => 'enter the days as a number, like 15 or 7.5.',
                'days.min' => 'credits cannot be negative.',
                'days.max' => 'no leave type grants more than 999 days in a year.',
                'days.multiple_of' => 'credits are counted in half days, so use steps of 0.5.',
            ],
        );

        return $validator->errors()->first('days') ?: null;
    }

    /**
     * "7,5" typed in a comma-decimal locale is 7.5. Anything else is passed on
     * untouched for the rules to judge.
     */
    private function decimal(string $raw): string
    {
        $value = trim($raw);

        return preg_match('/^\d+,\d+$/', $value) === 1 ? str_replace(',', '.', $value) : $value;
    }

    /**
     * @return Collection<int, LeaveType>
     */
    private function activeTypes(): Collection
    {
        return LeaveType::query()->active()->ordered()->get(['id', 'code']);
    }

    /**
     * @param  array<int, int|string>  $employeeIds
     * @return array<int, array<int, float>> employee id => leave type id => days
     */
    private function figuresOnFile(array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $figures = [];

        LeaveCredit::query()
            ->forEmployees(array_map('intval', $employeeIds))
            ->forYear($year)
            ->get(['employee_id', 'leave_type_id', 'days'])
            ->each(function (LeaveCredit $credit) use (&$figures): void {
                $figures[$credit->employee_id][$credit->leave_type_id] = $credit->days;
            });

        return $figures;
    }
}
