<?php

declare(strict_types=1);

use App\Exports\EmployeeMasterlistTemplate;
use App\Imports\EmployeeMasterlist;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

/**
 * A CSV upload with the given lines, the first being the heading row.
 *
 * @param  list<string>  $lines
 */
function masterlistCsv(array $lines, string $name = 'masterlist.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode("\r\n", $lines)."\r\n");
}

/**
 * An .xlsx upload with the given rows, the first being the heading row.
 *
 * A workbook actually written by PhpSpreadsheet rather than a fake file with
 * the right name, because the bytes are the thing under test: what Excel
 * stores for a number, and the row numbers the import reads back.
 *
 * @param  list<list<string|int|float|null>>  $rows
 */
function masterlistWorkbook(array $rows, string $name = 'masterlist.xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);

    $path = (string) tempnam(sys_get_temp_dir(), 'masterlist');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

/**
 * The template this user downloads, saved somewhere the test can work on it.
 *
 * @return string the path to a copy of the downloaded file
 */
function templateFile(User $user): string
{
    $response = test()->actingAs($user)->get(route('employees.import.template'))->assertSuccessful();

    $path = (string) tempnam(sys_get_temp_dir(), 'template');
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return $path;
}

/**
 * The template this user downloads, opened back up as a workbook.
 */
function downloadedTemplate(User $user): Spreadsheet
{
    return IOFactory::load(templateFile($user));
}

/** The employee number the next import will issue when nothing is on record. */
function firstEmployeeNo(int $sequence = 1): string
{
    return now()->format('Y').'-'.sprintf('%05d', $sequence);
}

/** HR's own headings, which are the only ones the import reads. */
const ADMIN_HEADINGS = 'NO.,LAST NAME,FIRST NAME,MIDDLE NAME,POSITION TITLE,SG,RATE';

/*
|--------------------------------------------------------------------------
| The template
|--------------------------------------------------------------------------
*/

it('sends guests to the sign-in page', function (): void {
    $this->post(route('employees.import.store'))->assertRedirect(route('login'));
});

it('gives HR Staff a template without the RATE column', function (): void {
    $sheet = downloadedTemplate(hrStaff())->getSheet(0);

    // SG closes up against POSITION TITLE, so the columns HR has to fill are
    // never left sitting past an empty one.
    expect($sheet->rangeToArray('A1:F1')[0])->toBe([
        'NO.', 'LAST NAME', 'FIRST NAME', 'MIDDLE NAME', 'POSITION TITLE', 'SG',
    ]);
});

it('gives an HR Admin the full template, laid out for filling in', function (): void {
    $sheet = downloadedTemplate(hrAdmin())->getSheet(0);

    expect($sheet->rangeToArray('A1:G1')[0])->toBe(explode(',', ADMIN_HEADINGS))
        // There is no sample row to delete, so nothing can be imported by
        // forgetting to delete it.
        ->and($sheet->rangeToArray('A2:G2', null, false, false)[0])->toBe(array_fill(0, 7, null))
        ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getFreezePane())->toBe('A2');
});

it('leaves the office and the item number off the template entirely', function (): void {
    Office::factory()->create(['name' => 'City Treasurer']);

    $book = downloadedTemplate(hrAdmin());

    // A plantilla sheet names no office, and its item number belongs to the
    // position rather than the person, so the template offers neither — and
    // carries no hidden sheet of office names to feed a dropdown. HR fills both
    // in on the employee's page after importing.
    $headings = $book->getSheet(0)->rangeToArray('A1:J1', null, false, false)[0];

    expect($book->getSheetCount())->toBe(1)
        ->and($headings)->not->toContain('OFFICE')
        ->and($headings)->not->toContain('ITEM NO.')
        ->and($book->getSheet(0)->dataValidationExists('I2'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Previewing
|--------------------------------------------------------------------------
*/

it('previews every row with the problems that would stop it', function (): void {
    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '1,Dela Cruz,Juan,Pascual,Administrative Aide IV,4,"23,450.00"',
        '2,Santos,,,Accountant I,12,',
        '3,Dela Cruz,Juan,Pascual,Administrative Aide IV,4,',
        '4,Bautista,Ana,,Clerk III,40,',
    ]);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        ->assertJsonPath('problemCount', 3)
        ->assertJsonPath('rows.0.line', 2)
        ->assertJsonPath('rows.0.name', 'Dela Cruz, Juan P.')
        // Issued here, not read from the file: the only number in HR's sheet is
        // the plantilla item, which belongs to the position.
        ->assertJsonPath('rows.0.employeeNo', firstEmployeeNo())
        ->assertJsonPath('rows.0.errors', [])
        ->assertJsonPath('rows.1.errors', ['The first name field is required.'])
        ->assertJsonPath('rows.2.errors', ['Dela Cruz, Juan P. is also on row 2.'])
        ->assertJsonPath('rows.3.errors', ['Salary grades run from 1 to 33.']);

    expect(Employee::query()->count())->toBe(0);
});

it('ignores an ITEM NO. column the sheet still carries', function (): void {
    // HR's own sheets keep the column, and it is theirs to keep: the item
    // belongs to the position and is handed to whoever fills it next, and HR
    // leaves it blank on some rows and repeats it on others while a hand-over
    // is processed. Reading it only stopped rows that were otherwise fine, so
    // it is passed over like the NO. column beside it.
    $file = masterlistCsv([
        'NO.,ITEM NO.,LAST NAME,FIRST NAME,MIDDLE NAME,POSITION TITLE,SG,RATE',
        '1,7,Dela Cruz,Juan,Pascual,Clerk III,6,',
        '2,7,Santos,Maria,,Accountant I,12,',
    ]);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHasNoErrors();

    // The repeated item no. 7 stopped neither row, and neither row kept it.
    expect(Employee::query()->whereNotNull('item_no')->count())->toBe(0)
        ->and(Employee::query()->count())->toBe(2);
});

it('flags someone already on the masterlist', function (): void {
    Employee::factory()->create([
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Pascual',
        'position_title' => 'Clerk III',
    ]);

    // The sheet names no office, so the match cannot be narrowed to one: a name
    // and a position that are both already on record is what a sheet imported
    // twice looks like, wherever the person is filed. A genuine namesake in the
    // same position elsewhere in the city is added by hand, which is the right
    // way round — a refused row is a row HR reads, and a quietly duplicated
    // employee is a second leave history nobody notices.
    $file = masterlistCsv([ADMIN_HEADINGS, '1,Dela Cruz,Juan,Pascual,Clerk III,6,']);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('problemCount', 1)
        ->assertJsonPath('rows.0.errors', [
            'Dela Cruz, Juan P. is already on the masterlist as Clerk III. If this is someone else, take the row out and add them by hand.',
        ]);
});

it('lets a namesake in another position through', function (): void {
    Employee::factory()->create([
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Pascual',
        'position_title' => 'Clerk III',
    ]);

    // Name alone would flag the two Dela Cruzes that any city payroll has.
    $file = masterlistCsv([ADMIN_HEADINGS, '1,Dela Cruz,Juan,Pascual,Accountant I,12,']);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        ->assertJsonPath('problemCount', 0);
});

it('refuses a file missing a required column', function (): void {
    $file = masterlistCsv(['NO.,ITEM NO.,LAST NAME,FIRST NAME', '1,1,Dela Cruz,Juan']);

    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'file' => 'The file is missing the "POSITION TITLE" column. Download the template to see the headings the import expects.',
        ]);
});

it('finds the headings under the title block of a printed plantilla', function (): void {
    $file = masterlistWorkbook([
        ['CITY GOVERNMENT OF SAMPLE CITY', null, null, null, null, null, null],
        ['PLANTILLA OF PERSONNEL', null, null, null, null, null, null],
        [null, null, null, null, null, null, null],
        explode(',', ADMIN_HEADINGS),
        [1, 'Dela Cruz', 'Juan', 'Pascual', 'Clerk III', 6, 23450],
    ]);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        // Row 5 in Excel, which is where HR would scroll to: the title rows are
        // passed over rather than cut out, so nothing is renumbered.
        ->assertJsonPath('rows.0.line', 5);
});

it('refuses a file with no employees in it', function (): void {
    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => masterlistCsv([ADMIN_HEADINGS])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('refuses a file that is neither a workbook nor a CSV', function (): void {
    $file = UploadedFile::fake()->create('masterlist.pdf', 10, 'application/pdf');

    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Choose an .xlsx or .csv file. Download the template and fill that in.']);
});

it('explains a workbook it cannot open instead of failing', function (): void {
    // An upload that stopped halfway: the file starts like a workbook and
    // then runs out, which is what PhpSpreadsheet throws on.
    $whole = Excel::raw(new EmployeeMasterlistTemplate(explode(',', ADMIN_HEADINGS)), ExcelFormat::XLSX);

    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('masterlist.xlsx', substr($whole, 0, 2000)),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'file' => 'This file could not be opened as an Excel workbook. Open it in Excel and save it again as .xlsx, or download the template and fill that in.',
        ]);
});

it('reads a workbook filled in from the template', function (): void {
    $file = masterlistWorkbook([
        explode(',', ADMIN_HEADINGS),
        [1, 'Peña', 'Juan', 'Pascual', 'Administrative Aide IV', 4, 23450.5],
        [null, null, null, null, null, null, null],
        [2, 'Santos', 'Maria', null, 'Accountant I', 40, null],
    ]);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        ->assertJsonPath('problemCount', 1)
        ->assertJsonPath('rows.0.line', 2)
        ->assertJsonPath('rows.0.name', 'Peña, Juan P.')
        // Excel stores a salary grade typed as 4 as the number 4.0, and a rule
        // written for what a person typed would refuse "4.0".
        ->assertJsonPath('rows.0.salaryGrade', '4')
        ->assertJsonPath('rows.0.errors', [])
        // The blank row 3 is dropped without renumbering what follows, so the
        // row this names is the row HR scrolls to in Excel.
        ->assertJsonPath('rows.1.line', 4)
        ->assertJsonPath('rows.1.errors', ['Salary grades run from 1 to 33.']);
});

it("reads HR's own sheet as HR keeps it", function (): void {
    /*
     * The real plantilla sheet HR sent a screenshot of, copied down to the
     * things that make it awkward:
     *
     *  - a column hidden between SG and RATE, so RATE sits in column I rather
     *    than column H. Headings are matched by name, never by position.
     *  - an ITEM NO. column, blank on every row, which is passed over: HR fills
     *    it in on some sheets and not on others, and it belongs to the position
     *    rather than the person.
     *  - the rate typed as text, "13,846.00", which is what the green "number
     *    stored as text" flags in HR's sheet are.
     *  - no employee number, no office, no suffix, no employment status: the
     *    official sheet has no such columns and is imported without them.
     */
    $file = masterlistWorkbook([
        ['NO.', 'ITEM NO.', 'LAST NAME', 'FIRST NAME', 'MIDDLE NAME', 'POSITION TITLE', 'SG', null, 'RATE'],
        [1, null, 'ABA', 'CHRISTIAN', 'CORNELIO', 'Lifeguard', '3', null, '13,846.00'],
        [2, null, 'ABA', 'DANIEL ANGELO', 'VILLAGONZALO', 'Nutritionist Dietitian I', '11', null, '28,512.00'],
    ]);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', '2 employees added to the masterlist.');

    $lifeguard = Employee::query()->where('position_title', 'Lifeguard')->sole();

    expect($lifeguard->last_name)->toBe('ABA')
        ->and($lifeguard->first_name)->toBe('CHRISTIAN')
        ->and($lifeguard->middle_name)->toBe('CORNELIO')
        ->and($lifeguard->salary_grade)->toBe(3)
        ->and($lifeguard->monthly_rate)->toBe('13846.00')
        ->and($lifeguard->employee_no)->toBe(firstEmployeeNo())
        // Neither the item number nor the office is read, so neither is
        // invented: HR fills them in on the employee's page.
        ->and($lifeguard->item_no)->toBeNull()
        ->and($lifeguard->office_id)->toBeNull();

    // Two people with the same surname and no item numbers are still two
    // people: nothing in this sheet tells them apart but their given names.
    expect(Employee::query()->where('last_name', 'ABA')->count())->toBe(2);
});

it('refuses a workbook too long to read all of, rather than importing part of it', function (): void {
    $rows = [explode(',', ADMIN_HEADINGS)];

    // One row past what the reader parses. Each row holds a space, so it is a
    // row in the file that counts as no employee once trimmed: the length of
    // the file, not the number of people in it, is what makes this unreadable.
    for ($row = 0; $row < EmployeeMasterlist::ROWS; $row++) {
        $rows[] = [' ', null, null, null, null, null, null];
    }

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => masterlistWorkbook($rows)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'The file has more than 5,000 rows. Split it into smaller files.']);
})->skip(fn (): bool => ! extension_loaded('zip'), 'Writing a workbook this size needs the zip extension.');

it('reads only the first sheet of a workbook', function (): void {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        explode(',', ADMIN_HEADINGS),
        [1, 'Dela Cruz', 'Juan', null, 'Clerk III', null, null],
    ], null, 'A1', true);

    // HR's own workbooks carry a sheet per office. Only the sheet in front of
    // them may be read.
    $book->createSheet()->setTitle('Second Office')->fromArray([
        explode(',', ADMIN_HEADINGS),
        [1, 'Reyes', 'Ana', null, 'Clerk I', null, null],
    ], null, 'A1', true);

    $path = (string) tempnam(sys_get_temp_dir(), 'masterlist');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('masterlist.xlsx', (string) file_get_contents($path)),
        ])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        ->assertJsonPath('problemCount', 0);
});

it('imports the template it hands out, once it is filled in', function (): void {
    // The real template, typed into and saved, rather than a workbook built
    // to look like it: this is the only test where both halves of the round
    // trip are the code under test.
    $path = templateFile(hrAdmin());
    $book = IOFactory::load($path);
    $book->getSheet(0)->fromArray(
        [[1, 'Dela Cruz', 'Juan', null, 'Clerk III', 6, 23450]],
        null,
        'A2',
        true,
    );
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('employee-masterlist-template.xlsx', (string) file_get_contents($path)),
        ])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', '1 employee added to the masterlist.');

    $juan = Employee::query()->sole();

    expect($juan->employee_no)->toBe(firstEmployeeNo())
        ->and($juan->item_no)->toBeNull()
        ->and($juan->office_id)->toBeNull();
});

it('reads a Windows-1252 file saved by Excel', function (): void {
    $file = UploadedFile::fake()->createWithContent(
        'masterlist.csv',
        (string) mb_convert_encoding(ADMIN_HEADINGS."\r\n1,Peña,Juan,,Clerk,,\r\n", 'Windows-1252', 'UTF-8'),
    );

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('rows.0.name', 'Peña, Juan');
});

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

it('adds every row of a clean file and records the import', function (): void {
    $admin = hrAdmin();

    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '1,Dela Cruz,Juan,Pascual,Administrative Aide IV,4,"23,450.00"',
        '',
        '2,Santos,Maria,,Accountant I,,',
    ]);

    $this->actingAs($admin)
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', '2 employees added to the masterlist.');

    $juan = Employee::query()->where('last_name', 'Dela Cruz')->sole();

    expect($juan->employee_no)->toBe(firstEmployeeNo())
        ->and($juan->salary_grade)->toBe(4)
        ->and($juan->monthly_rate)->toBe('23450.00')
        // The item number, the office and the employment status are none of
        // them read from HR's sheet, so none is invented: HR fills them in on
        // the employee's page.
        ->and($juan->item_no)->toBeNull()
        ->and($juan->office_id)->toBeNull()
        ->and($juan->employment_status)->toBeNull()
        ->and($juan->is_active)->toBeTrue();

    $maria = Employee::query()->where('last_name', 'Santos')->sole();

    expect($maria->employee_no)->toBe(firstEmployeeNo(2))
        ->and($maria->middle_name)->toBeNull()
        ->and($maria->salary_grade)->toBeNull();

    $activity = Activity::query()->where('event', 'imported')->sole();

    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['count'])->toBe(2)
        ->and($activity->description)->toBe('Imported 2 employees from masterlist.csv');
});

it('issues employee numbers after the highest one already used this year', function (): void {
    // Kept by id, because the two below are generated and "Dela Cruz" is a
    // surname a generator hands out sooner or later: the imported employee is
    // the one that is neither of these.
    $existing = [
        Employee::factory()->create(['employee_no' => now()->format('Y').'-00099'])->id,
        // A number from an earlier year must not raise this year's sequence.
        Employee::factory()->create(['employee_no' => '2019-04000'])->id,
    ];

    $file = masterlistCsv([ADMIN_HEADINGS, '1,Dela Cruz,Juan,,Clerk III,6,']);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertSessionHasNoErrors();

    expect(Employee::query()->whereNotIn('id', $existing)->sole()->employee_no)->toBe(firstEmployeeNo(100));
});

it('keeps the rate Excel stored as a number', function (): void {
    $file = masterlistWorkbook([
        explode(',', ADMIN_HEADINGS),
        [1, 'Dela Cruz', 'Juan', null, 'Clerk III', 6, 23450.5],
    ]);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertSessionHasNoErrors();

    expect(Employee::query()->sole()->monthly_rate)->toBe('23450.50');
});

it('adds nothing when any row has a problem', function (): void {
    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '1,Dela Cruz,Juan,,Clerk III,6,',
        '2,Santos,,,Clerk III,,',
    ]);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertSessionHasErrors(['file' => '1 row still has problems, so nothing was added. Fix them in the file and preview it again.']);

    expect(Employee::query()->count())->toBe(0);
});

it('ignores the RATE column in a file imported by HR Staff', function (): void {
    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '1,Dela Cruz,Juan,,Clerk III,4,23450.00',
    ]);

    $staff = hrStaff();

    $this->actingAs($staff)
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertJsonPath('ignoredColumns', ['RATE']);

    $this->actingAs($staff)
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertSessionHasNoErrors();

    expect(Employee::query()->sole()->monthly_rate)->toBeNull();
});
