<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Exports\TallyWorkbook;
use App\Models\Employee;
use App\Models\User;
use App\Services\TallyFilters;
use App\Services\TallySheetPrint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel: the tally sheet as a workbook, one worksheet per office
 * (PROJECT_CONTEXT 5.7). Same filters, rows and text as the PDF.
 */
class TallyExcelController extends Controller
{
    public function __invoke(Request $request, TallySheetPrint $print): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(Permission::GenerateExports->value), 403);

        // A workbook costs far less than a PDF: 1,000 employees in 12 offices
        // measured at 1.4 s and about 104 MB, just under PHP's 128 MB default.
        // Raised for this request only, to leave room for larger offices.
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $filters = TallyFilters::fromRequest($request);
        $showRate = $user->can('viewSalary', Employee::class);
        $sheets = $print->sheets($filters, $showRate);
        $office = $print->officeName($filters);

        $workbook = new TallyWorkbook(
            sheets: $sheets,
            year: $filters->year,
            showRate: $showRate,
            signatories: $print->signatories(),
            exportedLine: 'Exported '.Carbon::now()->format('F j, Y g:i A').' by '.$user->name,
            filtersNote: $print->filtersNote($filters),
        );

        activity('export')
            ->causedBy($user)
            ->event('exported')
            ->withProperties([
                'format' => 'xlsx',
                'year' => $filters->year,
                'office' => $office,
                'filters' => $filters->toArray(),
                'sheets' => count($sheets),
                'employees' => array_sum(array_map(static fn (array $sheet): int => count($sheet['rows']), $sheets)),
            ])
            ->log("Exported the {$filters->year} leave tally sheet to Excel ({$office})");

        return Excel::download(
            $workbook,
            sprintf('leave-tally-%d-%s.xlsx', $filters->year, Str::slug($office)),
            ExcelFormat::XLSX,
        );
    }
}
