<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use Illuminate\Http\UploadedFile;
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

const ADMIN_HEADINGS = 'Employee No,Last Name,First Name,Middle Name,Suffix,Position Title,Office,Salary Grade,Employment Status,Monthly Rate';

/*
|--------------------------------------------------------------------------
| Who may import
|--------------------------------------------------------------------------
*/

it('sends guests to the sign-in page', function (): void {
    $this->post(route('employees.import.store'))->assertRedirect(route('login'));
});

it('gives HR Staff a template without the HR Admin columns', function (): void {
    $response = $this->actingAs(hrStaff())->get(route('employees.import.template'))->assertSuccessful();

    expect($response->streamedContent())
        ->toContain('Employee No,Last Name,First Name,Middle Name,Suffix,Position Title,Office,Salary Grade')
        ->not->toContain('Monthly Rate')
        ->not->toContain('Employment Status');
});

it('gives an HR Admin the full template', function (): void {
    $response = $this->actingAs(hrAdmin())->get(route('employees.import.template'))->assertSuccessful();

    expect($response->streamedContent())->toContain(ADMIN_HEADINGS);
});

/*
|--------------------------------------------------------------------------
| Previewing
|--------------------------------------------------------------------------
*/

it('previews every row with the problems that would stop it', function (): void {
    Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->create(['employee_no' => '2026-00001']);

    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '2026-00100,Dela Cruz,Juan,Pascual,Jr.,Administrative Aide IV,city treasurer,4,Permanent,"₱23,450.00"',
        '2026-00001,Santos,Maria,,,Accountant I,City Treasurer,12,,',
        '2026-00100,Reyes,Ana,,,Clerk,City Treasurer,,,',
        '2026-00102,Bautista,,,,Clerk,Office of Nowhere,40,,',
    ]);

    $this->actingAs(hrAdmin())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('readyCount', 1)
        ->assertJsonPath('problemCount', 3)
        ->assertJsonPath('rows.0.line', 2)
        ->assertJsonPath('rows.0.name', 'Dela Cruz, Juan P. Jr.')
        ->assertJsonPath('rows.0.officeName', 'City Treasurer')
        ->assertJsonPath('rows.0.errors', [])
        ->assertJsonPath('rows.1.errors', ['Employee number 2026-00001 is already on the masterlist.'])
        ->assertJsonPath('rows.2.errors', ['Employee number 2026-00100 is also used on row 2.'])
        ->assertJsonPath('rows.3.errors', [
            'The first name field is required.',
            'Salary grades run from 1 to 33.',
            '"Office of Nowhere" is not on the offices list. Use the name exactly as it appears under Settings → Offices.',
        ]);

    expect(Employee::query()->count())->toBe(1);
});

it('refuses a file missing a required column', function (): void {
    $file = masterlistCsv(['Employee No,Last Name,First Name', '2026-00100,Dela Cruz,Juan']);

    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'file' => 'The file is missing the "Position Title" and "Office" columns. Download the template to see the headings the import expects.',
        ]);
});

it('refuses a file with no employees in it', function (): void {
    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => masterlistCsv([ADMIN_HEADINGS])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('refuses a file that is not a CSV', function (): void {
    $file = UploadedFile::fake()->create('masterlist.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs(hrStaff())
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('reads a Windows-1252 file saved by Excel', function (): void {
    Office::factory()->create(['name' => 'City Treasurer']);

    $file = UploadedFile::fake()->createWithContent(
        'masterlist.csv',
        (string) mb_convert_encoding(ADMIN_HEADINGS."\r\n2026-00100,Peña,Juan,,,Clerk,City Treasurer,,,\r\n", 'Windows-1252', 'UTF-8'),
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
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    $admin = hrAdmin();

    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '2026-00100,Dela Cruz,Juan,Pascual,Jr.,Administrative Aide IV,City Treasurer,4,Permanent,"23,450.00"',
        '',
        '2026-00101,Santos,Maria,,,Accountant I,City Treasurer,,,',
    ]);

    $this->actingAs($admin)
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', '2 employees added to the masterlist.');

    $juan = Employee::query()->where('employee_no', '2026-00100')->sole();

    expect($juan->office_id)->toBe($office->id)
        ->and($juan->salary_grade)->toBe(4)
        ->and($juan->monthly_rate)->toBe('23450.00')
        ->and($juan->employment_status)->toBe('Permanent')
        ->and($juan->is_active)->toBeTrue();

    $maria = Employee::query()->where('employee_no', '2026-00101')->sole();

    expect($maria->middle_name)->toBeNull()
        ->and($maria->salary_grade)->toBeNull();

    $activity = Activity::query()->where('event', 'imported')->sole();

    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['count'])->toBe(2);
});

it('adds nothing when any row has a problem', function (): void {
    Office::factory()->create(['name' => 'City Treasurer']);

    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '2026-00100,Dela Cruz,Juan,,,Clerk,City Treasurer,,,',
        '2026-00101,Santos,,,,Clerk,City Treasurer,,,',
    ]);

    $this->actingAs(hrAdmin())
        ->post(route('employees.import.store'), ['file' => $file])
        ->assertSessionHasErrors(['file' => '1 row still has problems, so nothing was added. Fix them in the file and preview it again.']);

    expect(Employee::query()->count())->toBe(0);
});

it('ignores the HR Admin columns in a file imported by HR Staff', function (): void {
    Office::factory()->create(['name' => 'City Treasurer']);

    $file = masterlistCsv([
        ADMIN_HEADINGS,
        '2026-00100,Dela Cruz,Juan,,,Clerk,City Treasurer,4,Permanent,23450.00',
    ]);

    $staff = hrStaff();

    $this->actingAs($staff)
        ->postJson(route('employees.import.preview'), ['file' => $file])
        ->assertJsonPath('ignoredColumns', ['Employment Status', 'Monthly Rate'])
        ->assertJsonPath('rows.0.employmentStatus', null);

    $this->actingAs($staff)->post(route('employees.import.store'), ['file' => $file])->assertSessionHasNoErrors();

    $juan = Employee::query()->sole();

    expect($juan->monthly_rate)->toBeNull()
        ->and($juan->employment_status)->toBeNull();
});
