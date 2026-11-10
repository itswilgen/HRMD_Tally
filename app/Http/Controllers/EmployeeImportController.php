<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\EmployeeMasterlistTemplate;
use App\Http\Requests\Employees\ImportEmployeesRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Adding the masterlist from a file: download the template, preview the
 * filled-in file, then save it. The work is EmployeeImportService's.
 */
class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImportService $imports) {}

    /**
     * The blank workbook to fill in, laid out as HR's own plantilla sheet is.
     * An .xlsx rather than a CSV because HR fills it in in Excel either way,
     * and a workbook carries the headings and the column widths that make the
     * sheet readable, where a CSV would hand over a wall of commas.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $this->authorize('create', Employee::class);

        return Excel::download(
            new EmployeeMasterlistTemplate($this->imports->templateHeadings($this->user($request))),
            'employee-masterlist-template.xlsx',
            ExcelFormat::XLSX,
        );
    }

    /**
     * What saving this file would do. JSON rather than an Inertia page, like the
     * leave date preview: it answers a question about a form still open.
     */
    public function preview(ImportEmployeesRequest $request): JsonResponse
    {
        return response()->json($this->imports->preview(
            $request->file('file')?->getRealPath() ?: '',
            $this->user($request),
        ));
    }

    public function store(ImportEmployeesRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        $count = $this->imports->import(
            $file?->getRealPath() ?: '',
            $file?->getClientOriginalName() ?? 'the file',
            $this->user($request),
        );

        return to_route('employees.index')->with(
            'success',
            sprintf('%d %s added to the masterlist.', $count, $count === 1 ? 'employee' : 'employees'),
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
