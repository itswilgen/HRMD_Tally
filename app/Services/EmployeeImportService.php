<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\Employees\EmployeeRequest;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adding employees to the masterlist in bulk from a CSV file laid out like the
 * template HR downloads from the Employees page.
 *
 * The file is read twice: once to preview it and once to save it. Nothing is
 * kept on the server between the two, so the save checks every row again
 * against the database as it is at that moment and cannot be fooled by a file
 * that changed after the preview.
 *
 * Saving is all or nothing. A masterlist that is half imported leaves HR unsure
 * which rows made it, so a file with any problem row is refused whole and the
 * preview says exactly which rows to fix.
 *
 * @phpstan-type PreviewRow array{line: int, employeeNo: string, name: string, positionTitle: string, officeName: string|null, salaryGrade: string|null, employmentStatus: string|null, errors: list<string>}
 * @phpstan-type Preview array{rows: list<PreviewRow>, readyCount: int, problemCount: int, ignoredColumns: list<string>}
 */
class EmployeeImportService
{
    /** Enough for the city's whole masterlist, small enough to preview in one go. */
    public const MAX_ROWS = 5000;

    /**
     * Template heading => the key it fills. Headings are matched loosely
     * ("Employee No.", "employee_no" and "EMPLOYEE NO" are all the same), so a
     * file someone retyped the headings of still reads.
     */
    private const COLUMNS = [
        'Employee No' => 'employee_no',
        'Last Name' => 'last_name',
        'First Name' => 'first_name',
        'Middle Name' => 'middle_name',
        'Suffix' => 'suffix',
        'Position Title' => 'position_title',
        'Office' => 'office',
        'Salary Grade' => 'salary_grade',
        'Employment Status' => 'employment_status',
        'Monthly Rate' => 'monthly_rate',
    ];

    private const REQUIRED = ['employee_no', 'last_name', 'first_name', 'position_title', 'office'];

    public function __construct(private readonly EmployeeService $employees) {}

    /**
     * The headings of the template this user should fill in. HR Staff do not
     * get the two HR Admin columns: a column they are not allowed to fill is
     * one the import would only ignore.
     *
     * @return list<string>
     */
    public function templateHeadings(User $user): array
    {
        $allowed = $this->allowedKeys($user);

        return array_keys(array_filter(self::COLUMNS, static fn (string $key): bool => in_array($key, $allowed, true)));
    }

    /**
     * @return Preview
     *
     * @throws ValidationException when the file as a whole cannot be read
     */
    public function preview(string $path, User $user): array
    {
        [$rows, $ignored] = $this->checkFile($path, $user);

        $problems = count(array_filter($rows, static fn (array $row): bool => $row['errors'] !== []));

        return [
            'rows' => array_map(static function (array $row): array {
                unset($row['data']);

                return $row;
            }, $rows),
            'readyCount' => count($rows) - $problems,
            'problemCount' => $problems,
            'ignoredColumns' => $ignored,
        ];
    }

    /**
     * Adds every row of the file, or none of them.
     *
     * @return int how many employees were added
     *
     * @throws ValidationException when the file or any row in it has a problem
     */
    public function import(string $path, string $fileName, User $user): int
    {
        [$rows] = $this->checkFile($path, $user);

        $problems = count(array_filter($rows, static fn (array $row): bool => $row['errors'] !== []));

        if ($problems > 0) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    '%d %s still %s problems, so nothing was added. Fix them in the file and preview it again.',
                    $problems,
                    $problems === 1 ? 'row' : 'rows',
                    $problems === 1 ? 'has' : 'have',
                ),
            ]);
        }

        return DB::transaction(function () use ($rows, $fileName, $user): int {
            foreach ($rows as $row) {
                $this->employees->create($row['data']);
            }

            activity('employee')
                ->causedBy($user)
                ->event('imported')
                ->withProperties(['file' => $fileName, 'count' => count($rows)])
                ->log(sprintf('Imported %d employees from %s', count($rows), $fileName));

            return count($rows);
        });
    }

    /**
     * Reads the file and checks every row, returning each row with the data it
     * would save and the problems that stop it.
     *
     * @return array{0: list<array{line: int, employeeNo: string, name: string, positionTitle: string, officeName: string|null, salaryGrade: string|null, employmentStatus: string|null, errors: list<string>, data: array<string, mixed>}>, 1: list<string>}
     *
     * @throws ValidationException
     */
    private function checkFile(string $path, User $user): array
    {
        [$headings, $lines] = $this->read($path);

        $columns = $this->mapHeadings($headings);
        $allowed = $this->allowedKeys($user);

        $ignored = array_values(array_map(
            static fn (string $key): string => (string) array_search($key, self::COLUMNS, true),
            array_filter($columns, static fn (string $key): bool => ! in_array($key, $allowed, true)),
        ));

        $offices = $this->officesByName();
        $taken = $this->employeeNumbersInUse(array_map(
            fn (array $cells): string => $this->cell($cells, $columns, 'employee_no') ?? '',
            $lines,
        ));

        $rules = EmployeeRequest::fieldRules(
            canSetSalary: in_array('monthly_rate', $allowed, true),
            canSetEmploymentStatus: in_array('employment_status', $allowed, true),
        );
        // The office is resolved from its name below, with a message that
        // talks about the file rather than about a dropdown.
        unset($rules['office_id']);

        $seen = [];
        $rows = [];

        foreach ($lines as $line => $cells) {
            $data = [];

            foreach (array_unique($columns) as $key) {
                if ($key !== 'office' && in_array($key, $allowed, true)) {
                    $data[$key] = $this->cell($cells, $columns, $key);
                }
            }

            $data['salary_grade'] = $data['salary_grade'] ?? null;

            if (isset($data['monthly_rate'])) {
                // Excel formats money as "₱23,450.00"; the number is what counts.
                $rate = (string) preg_replace('/[^0-9.\-]/', '', $data['monthly_rate']);
                $data['monthly_rate'] = $rate === '' ? $data['monthly_rate'] : $rate;
            }

            $errors = Validator::make($data, $rules, EmployeeRequest::fieldMessages(), EmployeeRequest::fieldAttributes())
                ->errors()
                ->all();

            $officeName = $this->cell($cells, $columns, 'office');
            $office = $officeName === null ? null : ($offices[$this->normalise($officeName)] ?? null);

            if ($officeName === null) {
                $errors[] = 'The office is blank.';
            } elseif ($office === null) {
                $errors[] = "\"{$officeName}\" is not on the offices list. Use the name exactly as it appears under Settings → Offices.";
            }

            $employeeNo = $data['employee_no'] ?? '';

            if ($employeeNo !== '') {
                if (isset($taken[$employeeNo])) {
                    $errors[] = "Employee number {$employeeNo} is already on the masterlist.";
                } elseif (isset($seen[$employeeNo])) {
                    $errors[] = "Employee number {$employeeNo} is also used on row {$seen[$employeeNo]}.";
                } else {
                    $seen[$employeeNo] = $line;
                }
            }

            $rows[] = [
                'line' => $line,
                'employeeNo' => $employeeNo,
                'name' => $this->listName($data),
                'positionTitle' => (string) ($data['position_title'] ?? ''),
                'officeName' => $office->name ?? $officeName,
                'salaryGrade' => $data['salary_grade'],
                'employmentStatus' => $data['employment_status'] ?? null,
                'errors' => array_values($errors),
                'data' => [...$data, 'office_id' => $office?->id],
            ];
        }

        return [$rows, $ignored];
    }

    /**
     * The heading row and the non-blank data rows, keyed by their row number
     * in the spreadsheet (the headings are row 1), which is the number HR
     * looks for when the preview says a row has a problem.
     *
     * @return array{0: list<string>, 1: array<int, list<string>>}
     *
     * @throws ValidationException
     */
    private function read(string $path): array
    {
        $contents = (string) file_get_contents($path);

        // Excel adds a byte-order mark to "CSV UTF-8" files, which would
        // otherwise stick to the first heading.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Plain "CSV" from Excel on Windows is Windows-1252, so the ñ in
        // "Peña" arrives as a byte that is not valid UTF-8.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\r\n");
        // Excel in a locale that writes decimals with a comma separates
        // columns with a semicolon instead.
        $delimiter = is_string($firstLine) && substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Please try again.']);
        }

        fwrite($stream, $contents);
        rewind($stream);

        $headings = null;
        $lines = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;

            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($headings === null) {
                $headings = $cells;

                continue;
            }

            if (count($lines) === self::MAX_ROWS) {
                fclose($stream);

                throw ValidationException::withMessages([
                    'file' => sprintf('The file has more than %s employees. Split it into smaller files.', number_format(self::MAX_ROWS)),
                ]);
            }

            $lines[$line] = $cells;
        }

        fclose($stream);

        if ($headings === null || $lines === []) {
            throw ValidationException::withMessages([
                'file' => 'The file has no employees in it. Keep the heading row from the template and add one employee per row under it.',
            ]);
        }

        return [$headings, $lines];
    }

    /**
     * Cell position => the key it fills, for the headings the import knows.
     *
     * @param  list<string>  $headings
     * @return array<int, string>
     *
     * @throws ValidationException when a required column is missing
     */
    private function mapHeadings(array $headings): array
    {
        $known = [];

        foreach (self::COLUMNS as $heading => $key) {
            $known[$this->normalise($heading)] = $key;
            $known[$this->normalise($key)] = $key;
        }

        $columns = [];

        foreach ($headings as $position => $heading) {
            $key = $known[$this->normalise($heading)] ?? null;

            // The first column with a heading wins if it appears twice.
            if ($key !== null && ! in_array($key, $columns, true)) {
                $columns[$position] = $key;
            }
        }

        $missing = array_diff(self::REQUIRED, $columns);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'The file is missing the '.$this->headingList($missing).' '.(count($missing) === 1 ? 'column' : 'columns')
                    .'. Download the template to see the headings the import expects.',
            ]);
        }

        return $columns;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, string>  $columns
     */
    private function cell(array $cells, array $columns, string $key): ?string
    {
        $position = array_search($key, $columns, true);

        if ($position === false) {
            return null;
        }

        $value = $cells[$position] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * Which of the template's keys this user may fill.
     *
     * @return list<string>
     */
    private function allowedKeys(User $user): array
    {
        return array_values(array_filter(
            self::COLUMNS,
            static fn (string $key): bool => match ($key) {
                'monthly_rate' => $user->can('viewSalary', Employee::class),
                'employment_status' => $user->can('setEmploymentStatus', Employee::class),
                default => true,
            },
        ));
    }

    /**
     * One query for the offices list, keyed by the loosened name a file is
     * matched on. A name has to match a real office exactly apart from case
     * and spacing: guessing at a near match could file someone under the
     * wrong office without anyone noticing.
     *
     * @return array<string, Office>
     */
    private function officesByName(): array
    {
        $offices = [];

        foreach (Office::query()->get(['id', 'name']) as $office) {
            $offices[$this->normalise($office->name)] = $office;
        }

        return $offices;
    }

    /**
     * The employee numbers in the file that are already taken, in one query
     * per thousand rows. Deactivated and removed employees count: their number
     * still points at their leave history.
     *
     * @param  list<string>|array<int, string>  $numbers
     * @return array<string, true>
     */
    private function employeeNumbersInUse(array $numbers): array
    {
        $taken = [];

        foreach (array_chunk(array_values(array_unique(array_filter($numbers))), 1000) as $chunk) {
            foreach (Employee::withTrashed()->whereIn('employee_no', $chunk)->pluck('employee_no') as $number) {
                $taken[(string) $number] = true;
            }
        }

        return $taken;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listName(array $data): string
    {
        if (! is_string($data['last_name'] ?? null) || ! is_string($data['first_name'] ?? null)) {
            return '';
        }

        return (new Employee([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'suffix' => $data['suffix'] ?? null,
        ]))->list_name;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function headingList(array $keys): string
    {
        $names = array_map(
            static fn (string $key): string => '"'.array_search($key, self::COLUMNS, true).'"',
            array_values($keys),
        );

        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9ñ]+/u', ' ', mb_strtolower($text)));
    }
}
