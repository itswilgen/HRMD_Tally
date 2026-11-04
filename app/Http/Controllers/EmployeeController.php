<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Employees\DeleteEmployeesRequest;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employee masterlist: the list the yearly tally sheet is drawn from.
 *
 * Authorization is EmployeePolicy's job and the field-level gating of the
 * monthly rate and the employment status belongs to EmployeeRequest and
 * EmployeeResource. This controller reads filters and hands work to
 * EmployeeService.
 */
class EmployeeController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $search = trim((string) $request->query('search', ''));
        $letter = mb_strtoupper(trim((string) $request->query('letter', '')));
        $officeId = (int) $request->query('office', 0);
        $status = (string) $request->query('status', '');

        // A single letter only. Anything else is someone editing the URL, and
        // the honest answer to that is to ignore it rather than to error.
        $letter = preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '';

        $employees = Employee::query()
            // The list prints the office name on every row.
            ->with('office:id,name')
            // One subquery for the page: the row explains that an employee with
            // leave on file is deactivated rather than removed.
            ->withCount('leaveRecords')
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($letter !== '', fn ($query) => $query->startingWith($letter))
            ->when($officeId > 0, fn ($query) => $query->where('office_id', $officeId))
            // Default to the people currently being encoded for. Someone who has
            // left is still in the system, behind the "Deactivated" filter.
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status !== 'inactive'))
            ->orderedByName()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('employees/index', [
            'employees' => EmployeeResource::collection($employees),
            'offices' => $this->officeOptions(),
            'letters' => $this->lettersInUse(),
            'filters' => [
                'search' => $search,
                'letter' => $letter,
                'office' => $officeId > 0 ? (string) $officeId : '',
                'status' => $status,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('employees/create', [
            'offices' => $this->officeOptions(),
            'employmentStatuses' => $this->employmentStatusSuggestions($request),
            'can' => [
                'setEmploymentStatus' => $request->user()?->can('setEmploymentStatus', Employee::class) ?? false,
                'viewSalary' => $request->user()?->can('viewSalary', Employee::class) ?? false,
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create($request->validated());

        return to_route('employees.index')
            ->with('success', "{$employee->full_name} has been added to the masterlist.");
    }

    public function edit(Request $request, Employee $employee): Response
    {
        $this->authorize('update', $employee);

        $employee->loadCount('leaveRecords');

        return Inertia::render('employees/edit', [
            // resolve(), not toArray(): toArray() hands back the raw array with
            // the gated keys still holding a MissingValue placeholder, which
            // serialises into the page and defeats the point of gating them.
            // resolve() runs the filter that removes them.
            'employee' => (new EmployeeResource($employee->load('office:id,name')))->resolve($request),
            'offices' => $this->officeOptions(),
            'employmentStatuses' => $this->employmentStatusSuggestions($request),
            // The policy decides; the form only turns its decision into a
            // disabled field with a sentence saying who to ask.
            'can' => [
                'setEmploymentStatus' => $request->user()?->can('setEmploymentStatus', Employee::class) ?? false,
                'viewSalary' => $request->user()?->can('viewSalary', Employee::class) ?? false,
                'deactivate' => $request->user()?->can('deactivate', $employee) ?? false,
            ],
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->validated());

        return to_route('employees.index')
            ->with('success', "{$employee->full_name}'s record has been updated.");
    }

    public function activate(Employee $employee): RedirectResponse
    {
        $this->authorize('activate', $employee);

        $this->employees->setActive($employee, true);

        return back()->with('success', "{$employee->full_name} is on the encoding list again.");
    }

    public function deactivate(Employee $employee): RedirectResponse
    {
        $this->authorize('deactivate', $employee);

        $this->employees->setActive($employee, false);

        return back()->with(
            'success',
            "{$employee->full_name} has been deactivated. Their past leave records are unchanged.",
        );
    }

    /**
     * The ticked rows on the masterlist. Soft deletes what it can and reports
     * what it kept; the rule itself is EmployeeService::deleteMany's.
     */
    public function destroyMany(DeleteEmployeesRequest $request): RedirectResponse
    {
        $result = $this->employees->deleteMany($request->ids(), $this->user($request));

        $deleted = count($result['deleted']);
        $kept = count($result['kept']);

        $response = back();

        if ($deleted > 0) {
            $response->with('success', sprintf(
                '%d %s removed from the masterlist. An HR Admin can restore %s.',
                $deleted,
                $deleted === 1 ? 'employee' : 'employees',
                $deleted === 1 ? 'the record' : 'them',
            ));
        }

        // Not an error the user caused, but it belongs in the red toast: it is
        // the half of the action that did not happen, and it names who to go
        // and deactivate instead.
        if ($kept > 0) {
            $response->with('error', sprintf(
                '%d of the ticked %s leave on file and %s kept: %s. Deactivate %s instead, so the tally sheets they appear on keep reading correctly.',
                $kept,
                $kept === 1 ? 'employees has' : 'employees have',
                $kept === 1 ? 'was' : 'were',
                $this->nameList($result['kept']),
                $kept === 1 ? 'them' : 'them',
            ));
        }

        return $response;
    }

    /**
     * Names for a toast: enough to recognise who, without a sentence that
     * scrolls off the screen when someone ticks the whole page.
     *
     * @param  list<string>  $names
     */
    private function nameList(array $names): string
    {
        $shown = array_slice($names, 0, 4);
        $rest = count($names) - count($shown);

        return implode('; ', $shown).($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Only the suggestions an HR Admin is allowed to act on. HR Staff cannot
     * type into the field, so sending them the list would be sending them data
     * for a control they will never be shown.
     *
     * @return list<string>
     */
    private function employmentStatusSuggestions(Request $request): array
    {
        return ($request->user()?->can('setEmploymentStatus', Employee::class) ?? false)
            ? $this->employees->employmentStatusSuggestions()
            : [];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function officeOptions(): array
    {
        return array_values(
            Office::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Office $office): array => [
                    'value' => (int) $office->id,
                    'label' => $office->name,
                ])
                ->all()
        );
    }

    /**
     * Which initial letters actually have employees behind them, so the A-Z tabs
     * can grey out the rest instead of offering an empty list.
     *
     * One grouped query against employees_last_name_prefix_index.
     *
     * @return list<string>
     */
    private function lettersInUse(): array
    {
        return array_values(array_filter(
            Employee::query()
                ->selectRaw('distinct upper(substr(last_name, 1, 1)) as letter')
                ->orderBy('letter')
                ->pluck('letter')
                ->all(),
            static fn (mixed $letter): bool => is_string($letter),
        ));
    }
}
