<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Employees\ImportEmployeesRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adding the masterlist from a CSV file: download the template, preview the
 * filled-in file, then save it. The work is EmployeeImportService's.
 */
class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImportService $imports) {}

    public function template(Request $request): StreamedResponse
    {
        $this->authorize('create', Employee::class);

        $headings = $this->imports->templateHeadings($this->user($request));

        return response()->streamDownload(function () use ($headings): void {
            // The byte-order mark is what makes Excel open the file as UTF-8,
            // so a name typed with ñ survives the round trip.
            echo "\xEF\xBB\xBF".implode(',', $headings)."\r\n";
        }, 'employee-masterlist-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
