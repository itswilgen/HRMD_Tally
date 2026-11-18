<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportKind;
use App\Enums\Permission;
use App\Models\LeaveCredit;
use App\Models\User;
use App\Services\ExportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The three exports (PROJECT_CONTEXT 5.7, rule 22): the tally as a PDF and
 * as a workbook, and one year's credits as a workbook.
 *
 * Each is downloaded straight away when it is small, and built by the queue
 * worker when it is big enough to be slow (ExportKind::backgroundAbove), in
 * which case the browser lands on Downloads to wait for it. Either way the
 * file comes from ExportBuilder, so it is the same file.
 */
class ExportController extends Controller
{
    public function __construct(private readonly ExportBuilder $exports) {}

    public function tallyPdf(Request $request): Response|RedirectResponse
    {
        return $this->export(ExportKind::TallyPdf, $request);
    }

    public function tallyExcel(Request $request): Response|RedirectResponse
    {
        return $this->export(ExportKind::TallyExcel, $request);
    }

    public function creditsExcel(Request $request): Response|RedirectResponse
    {
        // Reading credits is what exporting them needs, besides the export
        // permission every export needs (rule 22).
        $this->authorize('viewAny', LeaveCredit::class);

        return $this->export(ExportKind::CreditsExcel, $request);
    }

    private function export(ExportKind $kind, Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(Permission::GenerateExports->value), 403);

        $filters = $this->exports->filtersFrom($kind, $request);
        $rows = $this->exports->rowCount($kind, $filters);
        $background = $rows > $kind->backgroundAbove();

        $this->exports->log($kind, $filters, $user, $rows, $background);

        if ($background) {
            $export = $this->exports->queue($kind, $filters, $user, $rows);

            return redirect()
                ->route('downloads.index', ['highlight' => $export->id])
                ->with('success', sprintf(
                    'Preparing %s for %s employees. It will be ready here in a moment.',
                    $export->file_name,
                    number_format($rows),
                ));
        }

        $file = $this->exports->fileName($kind, $filters);

        return response($this->exports->build($kind, $filters, $user), 200, [
            'Content-Type' => $kind->mimeType(),
            // The PDF opens in its tab, where HR prints it; workbooks download.
            'Content-Disposition' => ($kind === ExportKind::TallyPdf ? 'inline' : 'attachment').'; filename="'.$file.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
