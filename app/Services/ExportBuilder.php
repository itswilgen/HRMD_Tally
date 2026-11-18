<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExportKind;
use App\Exports\LeaveCreditsSheet;
use App\Exports\TallyWorkbook;
use App\Jobs\GenerateExportFile;
use App\Models\Employee;
use App\Models\ExportFile;
use App\Models\Office;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Every export the system makes (PROJECT_CONTEXT 5.7, rule 22), built one
 * way whether it is downloaded straight away or by the queue worker, so the
 * two are always the same file.
 *
 * Filters travel as a plain array — year, search, letter, office — so they
 * can be stored on an ExportFile and read back by the job.
 *
 * @phpstan-type ExportFilters array{year: int, search: string, letter: string, office: int}
 */
class ExportBuilder
{
    /** Long bond, 8.5 × 13 in, in points; dompdf turns it for landscape. */
    private const LONG_BOND = [0, 0, 612, 936];

    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    public function __construct(
        private readonly TallySheetPrint $print,
        private readonly LeaveCreditService $credits,
        private readonly LeaveCreditExport $creditExport,
    ) {}

    /**
     * The filters on screen, read as the page that offers the export reads
     * them: the tally clamps the year to its picker, credits to theirs.
     *
     * @return ExportFilters
     */
    public function filtersFrom(ExportKind $kind, Request $request): array
    {
        $tally = TallyFilters::fromRequest($request);

        return [
            'year' => $kind === ExportKind::CreditsExcel ? $this->credits->resolveYear($request->query('year')) : $tally->year,
            'search' => $tally->search,
            'letter' => $tally->letter,
            'office' => $tally->officeId,
        ];
    }

    /**
     * How many employees the file covers, which decides where it is built.
     *
     * @param  ExportFilters  $filters
     */
    public function rowCount(ExportKind $kind, array $filters): int
    {
        return $kind === ExportKind::CreditsExcel
            ? $this->credits->filteredEmployees($filters['year'], $filters['search'], $filters['letter'], $filters['office'])->count()
            : $this->tallyFilters($filters)->employees()->count();
    }

    /**
     * @param  ExportFilters  $filters
     */
    public function fileName(ExportKind $kind, array $filters): string
    {
        $prefix = $kind === ExportKind::CreditsExcel ? 'leave-credits' : 'leave-tally';

        return sprintf('%s-%d-%s.%s', $prefix, $filters['year'], Str::slug($this->officeName($filters)), $kind->extension());
    }

    /**
     * "Leave tally (PDF) · 2026 · All offices", as Downloads lists it.
     *
     * @param  ExportFilters  $filters
     */
    public function title(ExportKind $kind, array $filters): string
    {
        return "{$kind->label()} · {$filters['year']} · {$this->officeName($filters)}";
    }

    /**
     * The file's bytes. Raises PHP's memory limit for this work only.
     *
     * @param  ExportFilters  $filters
     */
    public function build(ExportKind $kind, array $filters, User $user): string
    {
        ini_set('memory_limit', $kind->memoryLimit());
        set_time_limit(600);

        return match ($kind) {
            ExportKind::TallyPdf => $this->tallyPdf($filters, $user),
            ExportKind::TallyExcel => $this->tallyExcel($filters, $user),
            ExportKind::CreditsExcel => $this->creditsExcel($filters, $user),
        };
    }

    /**
     * Records the request for the file, and starts the background build.
     *
     * @param  ExportFilters  $filters
     */
    public function queue(ExportKind $kind, array $filters, User $user, int $rows): ExportFile
    {
        ExportFile::tidy();

        $export = ExportFile::query()->create([
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => ExportFile::STATUS_QUEUED,
            'filters' => $filters,
            'title' => $this->title($kind, $filters),
            'file_name' => $this->fileName($kind, $filters),
            'rows' => $rows,
        ]);

        GenerateExportFile::dispatch($export->id);

        return $export;
    }

    /**
     * Who asked for which file, in the activity log, whether it is built now
     * or in the background.
     *
     * @param  ExportFilters  $filters
     */
    public function log(ExportKind $kind, array $filters, User $user, int $rows, bool $background): void
    {
        $office = $this->officeName($filters);

        $description = match ($kind) {
            ExportKind::TallyPdf => "Printed the {$filters['year']} leave tally sheet ({$office})",
            ExportKind::TallyExcel => "Exported the {$filters['year']} leave tally sheet to Excel ({$office})",
            ExportKind::CreditsExcel => "Exported the {$filters['year']} leave credits to Excel ({$office})",
        };

        activity('export')
            ->causedBy($user)
            ->event($kind === ExportKind::TallyPdf ? 'printed' : 'exported')
            ->withProperties([
                'format' => $kind->extension(),
                'what' => $kind === ExportKind::CreditsExcel ? 'credits' : 'tally',
                'year' => $filters['year'],
                'office' => $office,
                'filters' => ['search' => $filters['search'], 'letter' => $filters['letter'], 'office' => $filters['office'] > 0 ? (string) $filters['office'] : ''],
                'employees' => $rows,
                'background' => $background,
            ])
            ->log($description);
    }

    /**
     * @param  ExportFilters  $filters
     */
    public function officeName(array $filters): string
    {
        if ($filters['office'] === 0) {
            return 'All offices';
        }

        $name = Office::query()->whereKey($filters['office'])->value('name');

        return is_string($name) ? $name : 'Unknown office';
    }

    /**
     * @param  ExportFilters  $filters
     */
    private function tallyFilters(array $filters): TallyFilters
    {
        return new TallyFilters($filters['year'], $filters['search'], $filters['letter'], $filters['office']);
    }

    /**
     * The printed sheet (5.7). dompdf holds it all in memory while laying it
     * out: about 0.7 MB per row (1,000 rows measured at 480 MB, 10 s).
     *
     * @param  ExportFilters  $filters
     */
    private function tallyPdf(array $filters, User $user): string
    {
        $tally = $this->tallyFilters($filters);

        // RA 10173: the rate prints only for someone who sees it on screen.
        $showRate = $user->can('viewSalary', Employee::class);

        $pdf = Pdf::loadView('pdf.tally-sheet', [
            'sheets' => $this->print->sheets($tally, $showRate),
            'year' => $filters['year'],
            'showRate' => $showRate,
            'months' => self::MONTHS,
            'filtersNote' => $this->print->filtersNote($tally),
            'signatories' => $this->print->signatories(),
            'seal' => $this->seal(),
        ])->setPaper(self::LONG_BOND, 'landscape');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        // Stamped after layout, when the page count is known.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans') ?? $dompdf->getFontMetrics()->getFont('Helvetica');

        if ($font !== null) {
            $stamp = 'Printed '.Carbon::now()->format('F j, Y g:i A').' by '.$user->name;
            $canvas->page_text(24, 590, $stamp, $font, 6.5, [0.35, 0.35, 0.35]);
            $canvas->page_text(860, 590, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 6.5, [0.35, 0.35, 0.35]);
        }

        return (string) $dompdf->output();
    }

    /**
     * @param  ExportFilters  $filters
     */
    private function tallyExcel(array $filters, User $user): string
    {
        $tally = $this->tallyFilters($filters);
        $showRate = $user->can('viewSalary', Employee::class);

        return (string) Excel::raw(new TallyWorkbook(
            sheets: $this->print->sheets($tally, $showRate),
            year: $filters['year'],
            showRate: $showRate,
            signatories: $this->print->signatories(),
            exportedLine: $this->exportedLine($user),
            filtersNote: $this->print->filtersNote($tally),
        ), ExcelFormat::XLSX);
    }

    /**
     * @param  ExportFilters  $filters
     */
    private function creditsExcel(array $filters, User $user): string
    {
        $data = $this->creditExport->build($filters['year'], $filters['search'], $filters['letter'], $filters['office']);

        return (string) Excel::raw(new LeaveCreditsSheet(
            year: $filters['year'],
            types: $data['types'],
            rows: $data['rows'],
            exportedLine: $this->exportedLine($user),
            filtersNote: $this->creditsFiltersNote($filters),
        ), ExcelFormat::XLSX);
    }

    private function exportedLine(User $user): string
    {
        return 'Exported '.Carbon::now()->format('F j, Y g:i A').' by '.$user->name;
    }

    /**
     * Said on the credits sheet, which has one worksheet for every office,
     * so a filtered export is never taken for the whole year.
     *
     * @param  ExportFilters  $filters
     */
    private function creditsFiltersNote(array $filters): string
    {
        $office = $this->officeName($filters);
        $parts = $office === 'All offices' ? [] : ["Office: {$office}"];

        if ($filters['letter'] !== '') {
            $parts[] = "Last names starting with {$filters['letter']}";
        }

        if ($filters['search'] !== '') {
            $parts[] = "Matching \"{$filters['search']}\"";
        }

        return $parts === [] ? '' : 'Filtered: '.implode(' · ', $parts);
    }

    /** The seal as a data URI: dompdf reads no files outside its chroot. */
    private function seal(): string
    {
        $path = public_path('images/brand/tanjay-seal-256.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : '';
    }
}
