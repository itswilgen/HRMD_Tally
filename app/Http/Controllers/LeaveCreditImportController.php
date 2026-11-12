<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\LeaveCreditTemplate;
use App\Http\Requests\Credits\ImportCreditsRequest;
use App\Models\LeaveCredit;
use App\Models\User;
use App\Services\LeaveCreditImportService;
use App\Services\LeaveCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Filling a year's credits from Excel: download the year's sheet, preview the
 * filled-in file, then save it. The work is LeaveCreditImportService's.
 */
class LeaveCreditImportController extends Controller
{
    public function __construct(
        private readonly LeaveCreditImportService $imports,
        private readonly LeaveCreditService $credits,
    ) {}

    /**
     * The year's credits sheet, with the figures already on file filled in.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $this->authorize('manage', LeaveCredit::class);

        $year = $this->credits->resolveYear($request->query('year'));

        return Excel::download(
            new LeaveCreditTemplate($year, $this->imports->templateHeadings(), $this->imports->templateRows($year)),
            "leave-credits-{$year}.xlsx",
            ExcelFormat::XLSX,
        );
    }

    /**
     * What saving this file would change. JSON, like the masterlist preview:
     * it answers a question about a dialog that is still open.
     */
    public function preview(ImportCreditsRequest $request): JsonResponse
    {
        return response()->json($this->imports->preview(
            $request->file('file')?->getRealPath() ?: '',
            $request->integer('year'),
        ));
    }

    public function store(ImportCreditsRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $year = $request->integer('year');

        $result = $this->imports->import(
            $file?->getRealPath() ?: '',
            $file?->getClientOriginalName() ?? 'the file',
            $year,
            $this->user($request),
        );

        if ($result['figures'] === 0) {
            return back()->with('success', "Every figure in the file already matched the {$year} credits, so nothing was changed.");
        }

        return back()->with('success', sprintf(
            '%d %s credit %s saved for %d %s.',
            $result['figures'],
            $year,
            $result['figures'] === 1 ? 'figure' : 'figures',
            $result['employees'],
            $result['employees'] === 1 ? 'employee' : 'employees',
        ));
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
