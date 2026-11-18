<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Employee;
use App\Services\LeaveHistoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every leave day of one employee, across all years (PROJECT_CONTEXT 5.6).
 *
 * Read-only: days are added and changed on the tally, where the encoding
 * rules run. Both roles may open it, as both may read the tally.
 */
class EmployeeLeaveHistoryController extends Controller
{
    public function __invoke(Request $request, Employee $employee, LeaveHistoryService $history): Response
    {
        $this->authorize('view', $employee);

        $employee->loadMissing('office:id,name');
        $user = $request->user();

        return Inertia::render('employees/leave', [
            // Built here rather than through EmployeeResource, so the monthly
            // rate is not in this page's data at all, whoever is looking.
            'employee' => [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'listName' => $employee->list_name,
                'positionTitle' => $employee->position_title,
                'officeName' => $employee->office?->name,
                'isActive' => $employee->is_active,
            ],
            'history' => $history->forEmployee($employee),
            'currentYear' => (int) now()->format('Y'),
            'can' => [
                'viewCredits' => $user?->can(Permission::ViewCredits->value) ?? false,
                'editEmployee' => $user?->can('update', $employee) ?? false,
            ],
        ]);
    }
}
