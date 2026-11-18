<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Signatory;
use App\Models\User;
use App\Services\TallyFilters;
use App\Services\TallySheetPrint;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Print (PDF): the tally sheet on long bond paper, landscape, one sheet per
 * office (PROJECT_CONTEXT 5.7). Same filters and rows as the screen.
 */
class TallyPrintController extends Controller
{
    /** Long bond, 8.5 × 13 in, in points; dompdf turns it for landscape. */
    private const LONG_BOND = [0, 0, 612, 936];

    /** Enough for about 1,300 employee rows on one print. */
    private const MEMORY_LIMIT = '1024M';

    private const TIME_LIMIT_SECONDS = 120;

    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    public function __invoke(Request $request, TallySheetPrint $print): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(Permission::GenerateExports->value), 403);

        // dompdf holds the whole sheet in memory while it lays it out: about
        // 0.7 MB per employee row (653 rows measured at 474 MB, 7 s). The
        // limit is raised for this request only; every other request keeps
        // the default. Very large prints move to a background job in the
        // next Phase 2 step.
        ini_set('memory_limit', self::MEMORY_LIMIT);
        set_time_limit(self::TIME_LIMIT_SECONDS);

        $filters = TallyFilters::fromRequest($request);

        // RA 10173: the rate prints only for someone who sees it on screen.
        $showRate = $user->can('viewSalary', Employee::class);

        $sheets = $print->sheets($filters, $showRate);
        $printedAt = Carbon::now();

        $pdf = Pdf::loadView('pdf.tally-sheet', [
            'sheets' => $sheets,
            'year' => $filters->year,
            'showRate' => $showRate,
            'months' => self::MONTHS,
            'filtersNote' => $this->filtersNote($filters),
            'signatories' => Signatory::query()->ordered()->get(['label', 'name', 'position'])->toArray(),
            'seal' => $this->seal(),
        ])->setPaper(self::LONG_BOND, 'landscape');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        // Stamped after layout, when the page count is known.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans') ?? $dompdf->getFontMetrics()->getFont('Helvetica');

        if ($font !== null) {
            $canvas->page_text(24, 590, 'Printed '.$printedAt->format('F j, Y g:i A').' by '.$user->name, $font, 6.5, [0.35, 0.35, 0.35]);
            $canvas->page_text(860, 590, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 6.5, [0.35, 0.35, 0.35]);
        }

        activity('export')
            ->causedBy($user)
            ->event('printed')
            ->withProperties([
                'year' => $filters->year,
                'office' => $this->officeName($filters),
                'filters' => $filters->toArray(),
                'sheets' => count($sheets),
                'employees' => array_sum(array_map(static fn (array $sheet): int => count($sheet['rows']), $sheets)),
            ])
            ->log("Printed the {$filters->year} leave tally sheet ({$this->officeName($filters)})");

        $file = sprintf('leave-tally-%d-%s.pdf', $filters->year, Str::slug($this->officeName($filters)));

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            // Inline, so it opens in a browser tab where HR prints it.
            'Content-Disposition' => 'inline; filename="'.$file.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function officeName(TallyFilters $filters): string
    {
        if ($filters->officeId === 0) {
            return 'All offices';
        }

        $name = Office::query()->whereKey($filters->officeId)->value('name');

        return is_string($name) ? $name : 'Unknown office';
    }

    /**
     * The search and letter filters, said on the sheet, so a partial printout
     * is never mistaken for the whole office.
     */
    private function filtersNote(TallyFilters $filters): string
    {
        $parts = [];

        if ($filters->letter !== '') {
            $parts[] = "Last names starting with {$filters->letter}";
        }

        if ($filters->search !== '') {
            $parts[] = "Matching \"{$filters->search}\"";
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
