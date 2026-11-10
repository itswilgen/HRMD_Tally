<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\Employees\EmployeeRequest;
use App\Imports\EmployeeMasterlist;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Adding employees to the masterlist in bulk from an Excel workbook, or a CSV,
 * laid out the way HR's own plantilla sheet is: a row per line of the plantilla
 * and the columns HR already keeps, with nothing added to them. HR chooses a
 * file and nothing else.
 *
 * Which of the two formats it is only changes how the rows are lifted out of
 * the file. Everything past that point — the headings, the duplicate people,
 * the rules each row is held to — is the same work on the same list of strings,
 * so the two formats cannot drift apart.
 *
 * Neither the office nor the plantilla item number is read. A plantilla sheet
 * names no office in it — it is one office's worth of people, and which office
 * that is lives in the file name — and the item number in it belongs to the
 * position rather than to the person: HR's own sheets leave it blank on some
 * rows and repeat it on others while a hand-over is processed, so reading it
 * only stopped rows that were otherwise fine. Both are left blank for HR to
 * fill in on the employee's page, exactly as the suffix and the employment
 * status the sheet also does not carry are.
 *
 * The employee number is therefore issued here rather than read. The only
 * number in HR's sheet is that plantilla item number, and an item is handed on
 * to whoever fills the position next, so it cannot also be the number a
 * person's leave history hangs off.
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
 * @phpstan-type PreviewRow array{line: int, employeeNo: string, name: string, positionTitle: string, salaryGrade: string|null, errors: list<string>}
 * @phpstan-type Preview array{rows: list<PreviewRow>, readyCount: int, problemCount: int, ignoredColumns: list<string>}
 */
class EmployeeImportService
{
    /** Enough for the city's whole masterlist, small enough to preview in one go. */
    public const MAX_ROWS = 5000;

    /**
     * How far down the sheet to look for the heading row. A plantilla printed
     * for a folder carries a title block above the table; ten rows is more than
     * any of HR's sheets use for it and far short of a sheet where the headings
     * were simply never kept.
     */
    private const HEADING_SEARCH_ROWS = 10;

    /**
     * Template heading => the key it fills, worded as HR's own sheet words it.
     * Headings are matched loosely ("POSITION TITLE", "Position Title" and
     * "position_title" are all the same), so a file someone retyped the
     * headings of still reads.
     *
     * HR's sheet also opens with a "NO." column, which is only the line number
     * on the page, and carries an "ITEM NO." the import has no use for. Both
     * are deliberately absent here: an unknown heading is ignored, which is
     * exactly what should happen to them.
     *
     * These are HR's headings, in HR's order. The import adds no column of its
     * own, so the official part of a sheet HR already keeps can be pasted in as
     * it stands.
     */
    private const COLUMNS = [
        'LAST NAME' => 'last_name',
        'FIRST NAME' => 'first_name',
        'MIDDLE NAME' => 'middle_name',
        'POSITION TITLE' => 'position_title',
        'SG' => 'salary_grade',
        'RATE' => 'monthly_rate',
    ];

    /**
     * Other wordings for the same columns. The system's earlier template spelt
     * both of them out, and a file filled in from it should not stop reading
     * because the heading got shorter.
     */
    private const ALIASES = [
        'Salary Grade' => 'salary_grade',
        'Monthly Rate' => 'monthly_rate',
    ];

    private const REQUIRED = ['last_name', 'first_name', 'position_title'];

    public function __construct(private readonly EmployeeService $employees) {}

    /**
     * The headings of the template this user should fill in. HR Staff do not get
     * the RATE column: a column they are not allowed to fill is one the import
     * would only ignore.
     *
     * @return list<string>
     */
    public function templateHeadings(User $user): array
    {
        $allowed = $this->allowedKeys($user);

        $headings = array_keys(array_filter(
            self::COLUMNS,
            static fn (string $key): bool => in_array($key, $allowed, true),
        ));

        // The line number HR counts down the page with. The import ignores it,
        // but the template is HR's sheet and HR's sheet starts with it.
        return ['NO.', ...$headings];
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

        try {
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
        } catch (UniqueConstraintViolationException) {
            // The employee numbers were worked out from the masterlist as it
            // stood a moment ago, and someone else has used them since. The
            // transaction has already rolled back, so nothing was half added.
            throw ValidationException::withMessages([
                'file' => 'Someone else added employees while this file was being saved, so the employee numbers it had been given are now taken. Nothing was added. Preview the file and try again.',
            ]);
        }
    }

    /**
     * Reads the file and checks every row, returning each row with the data it
     * would save and the problems that stop it.
     *
     * @return array{0: list<array{line: int, employeeNo: string, name: string, positionTitle: string, salaryGrade: string|null, errors: list<string>, data: array<string, mixed>}>, 1: list<string>}
     *
     * @throws ValidationException
     */
    private function checkFile(string $path, User $user): array
    {
        [$columns, $lines] = $this->read($path);

        $allowed = $this->allowedKeys($user);

        $ignored = array_values(array_map(
            static fn (string $key): string => (string) array_search($key, self::COLUMNS, true),
            array_filter($columns, static fn (string $key): bool => ! in_array($key, $allowed, true)),
        ));

        $onMasterlist = $this->peopleOnMasterlist();
        $numbers = $this->nextEmployeeNumbers(count($lines));

        $rules = EmployeeRequest::fieldRules(
            canSetSalary: in_array('monthly_rate', $allowed, true),
            canSetEmploymentStatus: false,
        );
        // The office and the item number are not read from the sheet, and the
        // employee number is issued below, so none of the three is a rule a row
        // can fail.
        unset($rules['office_id'], $rules['item_no'], $rules['employee_no']);

        $seen = [];
        $rows = [];
        $position = 0;

        foreach ($lines as $line => $cells) {
            $data = [];

            foreach (array_unique($columns) as $key) {
                if (in_array($key, $allowed, true)) {
                    $data[$key] = $this->cell($cells, $columns, $key);
                }
            }

            $data['salary_grade'] = $data['salary_grade'] ?? null;

            if (isset($data['monthly_rate'])) {
                // HR's sheet formats money as "13,846.00"; the number is what counts.
                $rate = (string) preg_replace('/[^0-9.\-]/', '', $data['monthly_rate']);
                $data['monthly_rate'] = $rate === '' ? $data['monthly_rate'] : $rate;
            }

            $errors = Validator::make($data, $rules, EmployeeRequest::fieldMessages(), EmployeeRequest::fieldAttributes())
                ->errors()
                ->all();

            $name = $this->listName($data);
            $positionTitle = (string) ($data['position_title'] ?? '');

            /*
             * A person is taken to be the same person as one already on the
             * masterlist when their name and their position both match. Name
             * alone would flag the two Dela Cruzes that any city payroll has;
             * name and position together is what a sheet imported twice looks
             * like, and little else plausibly does.
             *
             * It is not narrowed to an office, because the sheet names none.
             * What that costs is a genuine namesake who holds the same
             * position in another office and now has to be added by hand, and
             * that is the right way round: a refused row is a row HR reads,
             * while a quietly duplicated employee is a second leave history
             * nobody notices.
             *
             * A row missing either name or position is left to the rules above,
             * which have already refused it for exactly that.
             */
            $identity = $this->normalise(implode(' ', [
                $data['last_name'] ?? '',
                $data['first_name'] ?? '',
                $data['middle_name'] ?? '',
                $positionTitle,
            ]));

            if ($name !== '' && $positionTitle !== '') {
                if (isset($onMasterlist[$identity])) {
                    $errors[] = sprintf(
                        '%s is already on the masterlist as %s. If this is someone else, take the row out and add them by hand.',
                        $name,
                        $positionTitle,
                    );
                } elseif (isset($seen[$identity])) {
                    $errors[] = sprintf('%s is also on row %d.', $name, $seen[$identity]);
                } else {
                    $seen[$identity] = $line;
                }
            }

            $employeeNo = $numbers[$position];
            $position++;

            $rows[] = [
                'line' => $line,
                'employeeNo' => $employeeNo,
                'name' => $name,
                'positionTitle' => $positionTitle,
                'salaryGrade' => $data['salary_grade'],
                'errors' => array_values($errors),
                // No office_id and no item_no: the sheet carries neither in a
                // form worth reading, so the record is saved without them and
                // HR fills them in on the employee's page.
                'data' => [...$data, 'employee_no' => $employeeNo],
            ];
        }

        return [$rows, $ignored];
    }

    /**
     * The columns the sheet names, and its data rows keyed by their row number
     * in the spreadsheet — the number HR looks for when the preview says a row
     * has a problem.
     *
     * @return array{0: array<int, string>, 1: array<int, list<string>>}
     *
     * @throws ValidationException
     */
    private function read(string $path): array
    {
        $rows = $this->isWorkbook($path) ? $this->readWorkbook($path) : $this->readCsv($path);

        // Rows HR deleted the contents of, and the empty rows Excel keeps below
        // the data. The row numbers of the rows that remain are untouched, so
        // they still match the file.
        $filled = array_filter($rows, static fn (array $cells): bool => implode('', $cells) !== '');

        [$headingRow, $columns] = $this->findHeadings($filled);

        $lines = [];

        foreach ($filled as $line => $cells) {
            if ($line <= $headingRow) {
                continue;
            }

            if (count($lines) === self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => sprintf('The file has more than %s employees. Split it into smaller files.', number_format(self::MAX_ROWS)),
                ]);
            }

            $lines[$line] = $cells;
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'file' => 'The file has no employees in it. Keep the heading row and put one employee per row under it.',
            ]);
        }

        return [$columns, $lines];
    }

    /**
     * Which row the headings are on, and what each of its cells fills.
     *
     * Not simply the first row: a plantilla printed for a folder carries a
     * title and the office above the table. The rows above the headings are
     * passed over rather than cut out, so every row number still matches the
     * file.
     *
     * @param  array<int, list<string>>  $rows
     * @return array{0: int, 1: array<int, string>}
     *
     * @throws ValidationException when no row in reach names the columns
     */
    private function findHeadings(array $rows): array
    {
        /** @var array{0: int, 1: array<int, string>}|null $best */
        $best = null;

        foreach (array_slice($rows, 0, self::HEADING_SEARCH_ROWS, true) as $line => $cells) {
            $columns = $this->mapHeadings($cells);

            if (array_diff(self::REQUIRED, $columns) === []) {
                return [$line, $columns];
            }

            // Kept so the message below can name what the likeliest heading row
            // is missing, rather than everything the import ever wanted.
            if ($best === null || count($columns) > count($best[1])) {
                $best = [$line, $columns];
            }
        }

        $missing = array_values(array_diff(self::REQUIRED, $best[1] ?? []));

        throw ValidationException::withMessages([
            'file' => 'The file is missing the '.$this->headingList($missing).' '.(count($missing) === 1 ? 'column' : 'columns')
                .'. Download the template to see the headings the import expects.',
        ]);
    }

    /**
     * Whether the file is a workbook rather than a CSV, decided by its first
     * four bytes. Every .xlsx is a ZIP archive and so begins with them, and no
     * CSV can, whatever the name on the file says — so a workbook someone
     * saved as "masterlist.csv" still reads, and the sniff cannot mistake a
     * CSV for a workbook.
     *
     * @throws ValidationException
     */
    private function isWorkbook(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Please try again.']);
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04";
    }

    /**
     * The first sheet of a workbook, as rows of text keyed by their row number
     * in Excel.
     *
     * Those row numbers are the array's own positions plus one: reading starts
     * at row 1 and App\Imports\EmployeeMasterlist skips nothing on the way in,
     * so position 0 is row 1. That is the whole reason it must not skip.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException
     */
    private function readWorkbook(string $path): array
    {
        try {
            // The reader type has to be named: the upload is a temporary file
            // with no extension for anyone to guess it from.
            $sheets = Excel::toArray(new EmployeeMasterlist, $path, null, ExcelFormat::XLSX);
        } catch (SpreadsheetException) {
            // A truncated upload, a password, or an .xls from 2003 renamed to
            // .xlsx. None of it is worth explaining; what to do about it is.
            // Only PhpSpreadsheet's own failures are turned into this message,
            // so a mistake in our own code still surfaces as a mistake.
            throw ValidationException::withMessages([
                'file' => 'This file could not be opened as an Excel workbook. Open it in Excel and save it again as .xlsx, or download the template and fill that in.',
            ]);
        }

        $rows = [];

        foreach (array_values($sheets[0] ?? []) as $position => $cells) {
            $rows[$position + 1] = array_map(
                fn (mixed $cell): string => $this->text($cell),
                array_values($cells),
            );
        }

        // Reading stopped at EmployeeMasterlist::ROWS, so a file that reaches
        // it may well have more below that was never parsed. Refusing is the
        // only honest answer: the row cap further down counts employees, and
        // it cannot count the ones nobody read. Importing what was read would
        // add part of a masterlist and report it as the whole thing.
        if (count($rows) >= EmployeeMasterlist::ROWS) {
            throw ValidationException::withMessages([
                'file' => sprintf('The file has more than %s rows. Split it into smaller files.', number_format(self::MAX_ROWS)),
            ]);
        }

        return $rows;
    }

    /**
     * A cell as the text the rest of the import reads.
     *
     * Excel hands back real types where a CSV only ever has strings: a salary
     * grade typed as 4 arrives as the number 4.0, and a rule written for what
     * a person typed would refuse "4.0". Whole numbers lose the decimal part;
     * everything else keeps exactly what was typed.
     */
    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            // A float too large to be whole has already lost its precision;
            // the guard only keeps sprintf from printing 20 digits of noise.
            is_float($value) => $value === floor($value) && abs($value) < 1e15
                ? sprintf('%.0F', $value)
                : trim((string) $value),
            // A cell someone styled a word of, which arrives as an object.
            $value instanceof RichText => trim($value->getPlainText()),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /**
     * Every row of a CSV, as text keyed by its line number in the file.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException
     */
    private function readCsv(string $path): array
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

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;

            $rows[$line] = array_map(
                static fn (?string $cell): string => trim((string) $cell),
                $cells,
            );
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Cell position => the key it fills, for the headings the import knows.
     *
     * @param  list<string>  $headings
     * @return array<int, string>
     */
    private function mapHeadings(array $headings): array
    {
        $known = [];

        foreach ([...self::COLUMNS, ...self::ALIASES] as $heading => $key) {
            $known[$this->normalise((string) $heading)] = $key;
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
            static fn (string $key): bool => $key === 'monthly_rate'
                ? $user->can('viewSalary', Employee::class)
                : true,
        ));
    }

    /**
     * As many unused employee numbers as the file has rows, in the YYYY-NNNNN
     * shape the masterlist already uses.
     *
     * Soft-deleted employees are counted: their number still points at their
     * leave history, so reissuing it would attach it to someone new.
     *
     * @return list<string>
     */
    private function nextEmployeeNumbers(int $count): array
    {
        $prefix = now()->format('Y').'-';
        $highest = 0;

        foreach (Employee::withTrashed()->where('employee_no', 'like', $prefix.'%')->pluck('employee_no') as $number) {
            // Lexical ordering would put "2026-9" after "2026-00010", so the
            // sequence is compared as the number it is.
            $highest = max($highest, (int) substr((string) $number, strlen($prefix)));
        }

        $numbers = [];

        for ($offset = 1; $offset <= $count; $offset++) {
            $numbers[] = $prefix.sprintf('%05d', $highest + $offset);
        }

        return $numbers;
    }

    /**
     * Everyone already on the masterlist, keyed by the name and position a row
     * is matched on, in one query for the whole file.
     *
     * The whole masterlist rather than one office's worth of it, because the
     * sheet names no office to narrow it to. That is four columns of a few
     * thousand rows — one small query, where the alternative is one query per
     * row of the file.
     *
     * Soft-deleted employees are left out: HR removed them on purpose, and a
     * sheet that lists them again is re-hiring, not double-importing.
     *
     * @return array<string, true>
     */
    private function peopleOnMasterlist(): array
    {
        $people = [];

        $columns = ['last_name', 'first_name', 'middle_name', 'position_title'];

        foreach (Employee::query()->get($columns) as $employee) {
            $people[$this->normalise(implode(' ', [
                $employee->last_name,
                $employee->first_name,
                $employee->middle_name ?? '',
                $employee->position_title,
            ]))] = true;
        }

        return $people;
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
