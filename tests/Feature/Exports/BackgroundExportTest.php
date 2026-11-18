<?php

declare(strict_types=1);

use App\Enums\ExportKind;
use App\Jobs\GenerateExportFile;
use App\Models\Employee;
use App\Models\ExportFile;
use App\Models\User;
use App\Services\ExportBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
| Large exports in the background (PROJECT_CONTEXT 5.7). The thresholds are
| lowered per test so a handful of employees counts as "large".
*/

beforeEach(function (): void {
    Storage::fake('local');
});

function makeLarge(ExportKind $kind, int $above = 2): void
{
    config()->set("exports.background_above.{$kind->value}", $above);
}

it('downloads a small export straight away', function (): void {
    Queue::fake();
    Employee::factory()->count(2)->create();

    $this->actingAs(hrAdmin())
        ->get(route('tally.print', ['year' => 2026]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Queue::assertNothingPushed();
    expect(ExportFile::query()->exists())->toBeFalse();
});

it('queues a large PDF and sends the browser to Downloads', function (): void {
    Queue::fake();
    makeLarge(ExportKind::TallyPdf);
    $admin = hrAdmin();
    Employee::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get(route('tally.print', ['year' => 2026]));

    $export = ExportFile::query()->sole();

    $response->assertRedirect(route('downloads.index', ['highlight' => $export->id]))
        ->assertSessionHas('success', 'Preparing leave-tally-2026-all-offices.pdf for 3 employees. It will be ready here in a moment.');

    expect($export->user_id)->toBe($admin->id)
        ->and($export->kind)->toBe(ExportKind::TallyPdf)
        ->and($export->status)->toBe(ExportFile::STATUS_QUEUED)
        ->and($export->rows)->toBe(3)
        ->and($export->filters)->toBe(['year' => 2026, 'search' => '', 'letter' => '', 'office' => 0])
        ->and($export->title)->toBe('Leave tally (PDF) · 2026 · All offices');

    Queue::assertPushed(GenerateExportFile::class, fn (GenerateExportFile $job): bool => $job->exportId === $export->id);

    $log = Activity::query()->where('log_name', 'export')->sole();

    expect($log->properties['background'])->toBeTrue()
        ->and($log->description)->toBe('Printed the 2026 leave tally sheet (All offices)');
});

it('queues the large Excel exports the same way', function (ExportKind $kind, string $route): void {
    Queue::fake();
    makeLarge($kind);
    Employee::factory()->count(3)->create();

    $this->actingAs(hrAdmin())->get(route($route))->assertRedirect();

    expect(ExportFile::query()->sole()->kind)->toBe($kind);
    Queue::assertPushed(GenerateExportFile::class);
})->with([
    'tally' => [ExportKind::TallyExcel, 'tally.excel'],
    'credits' => [ExportKind::CreditsExcel, 'credits.export'],
]);

it('builds the file in the worker, the same file as a straight download', function (): void {
    makeLarge(ExportKind::TallyExcel);
    $admin = hrAdmin();
    Employee::factory()->count(3)->create(['office_id' => null]);

    // The queue runs jobs at once in tests (sync), as a worker would.
    $this->actingAs($admin)->get(route('tally.excel', ['year' => 2026]))->assertRedirect();

    $export = ExportFile::query()->sole();

    expect($export->status)->toBe(ExportFile::STATUS_DONE)
        ->and($export->path)->toBe('exports/'.$export->id.'.xlsx')
        ->and($export->started_at)->not->toBeNull()
        ->and($export->finished_at)->not->toBeNull();

    Storage::disk('local')->assertExists((string) $export->path);

    $fromWorker = loadWorkbook((string) Storage::disk('local')->get((string) $export->path))->getSheet(0)->toArray();
    $direct = loadWorkbook(app(ExportBuilder::class)->build(ExportKind::TallyExcel, $export->filters, $admin))->getSheet(0)->toArray();

    // Row 5 holds the export time, which differs by a second or so.
    unset($fromWorker[4], $direct[4]);

    expect($fromWorker)->toBe($direct);
});

it('lists the user\'s own exports on Downloads, newest first', function (): void {
    $admin = hrAdmin();
    $other = hrStaff();
    $older = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_FAILED, 'filters' => [], 'title' => 'Older', 'file_name' => 'a.pdf', 'rows' => 1, 'error' => 'Broke']);
    $older->forceFill(['created_at' => Carbon::now()->subHour()->subMinute()])->save();
    ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyExcel, 'status' => ExportFile::STATUS_QUEUED, 'filters' => [], 'title' => 'Newer', 'file_name' => 'b.xlsx', 'rows' => 1]);
    ExportFile::query()->create(['user_id' => $other->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_QUEUED, 'filters' => [], 'title' => 'Someone else', 'file_name' => 'c.pdf', 'rows' => 1]);

    $this->actingAs($admin)
        ->get(route('downloads.index', ['highlight' => 'abc']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('downloads/index')
            ->has('exports', 2)
            ->where('exports.0.title', 'Newer')
            ->where('exports.0.status', 'queued')
            ->where('exports.1.title', 'Older')
            ->where('exports.1.error', 'Broke')
            ->where('highlight', 'abc')
        );
});

it('lets only the owner download a finished file', function (): void {
    $admin = hrAdmin();
    $other = hrStaff();
    Storage::disk('local')->put('exports/x.pdf', '%PDF-test');
    $export = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_DONE, 'filters' => [], 'title' => 'T', 'file_name' => 'leave-tally-2026-all-offices.pdf', 'rows' => 1, 'path' => 'exports/x.pdf', 'size' => 9]);

    $this->actingAs($admin)
        ->get(route('downloads.show', $export))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertStreamedContent('%PDF-test');

    $this->actingAs($other)->get(route('downloads.show', $export))->assertNotFound();
});

it('does not serve a file that is not ready', function (): void {
    $admin = hrAdmin();
    $export = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_RUNNING, 'filters' => [], 'title' => 'T', 'file_name' => 'a.pdf', 'rows' => 1]);

    $this->actingAs($admin)->get(route('downloads.show', $export))->assertNotFound();
});

it('refuses Downloads to an account without the export permission', function (): void {
    seedRolesAndPermissions();

    $this->actingAs(User::factory()->create())->get(route('downloads.index'))->assertForbidden();
});

it('deletes exports older than seven days, with their files', function (): void {
    $admin = hrAdmin();
    Storage::disk('local')->put('exports/old.pdf', 'old');
    Storage::disk('local')->put('exports/new.pdf', 'new');
    $old = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_DONE, 'filters' => [], 'title' => 'Old', 'file_name' => 'old.pdf', 'rows' => 1, 'path' => 'exports/old.pdf']);
    $old->forceFill(['created_at' => Carbon::now()->subDays(8)])->save();
    ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_DONE, 'filters' => [], 'title' => 'New', 'file_name' => 'new.pdf', 'rows' => 1, 'path' => 'exports/new.pdf']);

    $this->actingAs($admin)->get(route('downloads.index'))->assertOk();

    expect(ExportFile::query()->pluck('title')->all())->toBe(['New']);
    Storage::disk('local')->assertMissing('exports/old.pdf');
    Storage::disk('local')->assertExists('exports/new.pdf');
});

it('marks an export the worker never finished as failed, and says why', function (): void {
    $admin = hrAdmin();
    $stuck = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_QUEUED, 'filters' => [], 'title' => 'Stuck', 'file_name' => 'a.pdf', 'rows' => 1]);
    $stuck->forceFill(['created_at' => Carbon::now()->subMinutes(61)])->save();

    $this->actingAs($admin)
        ->get(route('downloads.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exports.0.status', 'failed')
            ->where('exports.0.error', 'It was never finished. The background worker may not be running: ask the system administrator.')
        );
});

it('records a failed build in plain words', function (): void {
    $admin = hrAdmin();
    $export = ExportFile::query()->create(['user_id' => $admin->id, 'kind' => ExportKind::TallyPdf, 'status' => ExportFile::STATUS_RUNNING, 'filters' => [], 'title' => 'T', 'file_name' => 'a.pdf', 'rows' => 1]);

    (new GenerateExportFile($export->id))->failed(new RuntimeException('Allowed memory size exhausted'));

    expect($export->fresh()?->status)->toBe(ExportFile::STATUS_FAILED)
        ->and($export->fresh()?->error)->toBe('The file could not be made. Try again, or choose one office at a time.');
});
