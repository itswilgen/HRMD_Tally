<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Credits\CopyCreditsRequest;
use App\Http\Requests\Credits\GrantCreditsRequest;
use App\Http\Requests\Credits\SaveCreditsGridRequest;
use App\Http\Requests\Credits\SaveEmployeeCreditsRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use App\Services\LeaveCreditService;
use App\Services\LeaveTallyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave credits: what HR granted, against what was taken.
 *
 * Two screens read the same figures. The grid fills a whole year down a column,
 * which is how HR works off a leave card pile; the per-employee page is for the
 * one person whose figures are not standard. Both render what
 * LeaveCreditService returns, and the "taken" half of every balance comes from
 * LeaveTallyService, so a balance cannot read one thing here and another on the
 * tally sheet (business rule 6).
 *
 * The controller reads filters and assembles props. Nothing is summed here.
 */
class LeaveCreditController extends Controller
{
    /** The same page length as the tally sheet, so the two read alike. */
    private const PER_PAGE = 25;

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly LeaveTallyService $tallies,
    ) {}

    /**
     * The grid: a year's figures for a page of employees.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveCredit::class);

        $year = $this->year($request);
        $search = trim((string) $request->query('search', ''));
        $letter = mb_strtoupper(trim((string) $request->query('letter', '')));
        $officeId = (int) $request->query('office', 0);

        $letter = preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '';

        $employees = $this->credits->filteredEmployees($year, $search, $letter, $officeId)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $employeeIds = array_values($employees->getCollection()
            ->map(static fn (Employee $employee): int => $employee->id)
            ->all());

        $leaveTypes = $this->leaveTypeColumns();

        return Inertia::render('credits/index', [
            'employees' => EmployeeResource::collection($employees),

            // Keyed by employee id, one lookup per row, exactly as the tally
            // page consumes its own rows.
            'credits' => $this->credits->forEmployees($employeeIds, $year, $this->columnIds($leaveTypes)),

            'leaveTypes' => $leaveTypes,
            'offices' => $this->officeOptions(),
            'letters' => $this->lettersInUse(),

            'year' => $year,
            'years' => $this->credits->selectableYears(),

            'filters' => [
                'search' => $search,
                'letter' => $letter,
                'office' => $officeId > 0 ? (string) $officeId : '',
            ],

            'can' => [
                'manage' => $request->user()?->can('manage', LeaveCredit::class) ?? false,
                // Reading credits is enough to export them (rule 22), with the
                // export permission every role that exports already has.
                'export' => $request->user()?->can(Permission::GenerateExports->value) ?? false,
            ],
        ]);
    }

    /**
     * One employee's credits for one year.
     */
    public function show(Request $request, Employee $employee): Response
    {
        $this->authorize('viewAny', LeaveCredit::class);

        $year = $this->year($request);
        $leaveTypes = $this->leaveTypeColumns();

        $employee->loadMissing('office:id,name');

        return Inertia::render('credits/show', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'credits' => $this->credits->forEmployee($employee, $year, $this->columnIds($leaveTypes)),

            'leaveTypes' => $leaveTypes,

            'year' => $year,
            'years' => $this->credits->selectableYears(),

            'can' => [
                'manage' => $request->user()?->can('manage', LeaveCredit::class) ?? false,
            ],
        ]);
    }

    /**
     * Save one employee's figures.
     */
    public function save(SaveEmployeeCreditsRequest $request, Employee $employee): RedirectResponse
    {
        $year = $request->integer('year');

        $changed = $this->credits->save(
            $employee,
            $year,
            $request->daysByType(),
            $this->user($request),
        );

        return back()->with(
            'success',
            $changed
                ? "{$year} leave credits saved for {$employee->list_name}."
                : "Nothing had changed, so {$employee->list_name}'s {$year} credits were left as they were.",
        );
    }

    /**
     * Save the grid.
     */
    public function update(SaveCreditsGridRequest $request): RedirectResponse
    {
        $year = $request->integer('year');

        $changed = $this->credits->saveMany(
            $request->daysByEmployee(),
            $year,
            $this->user($request),
        );

        if ($changed === 0) {
            return back()->with('success', "Nothing had changed, so the {$year} credits were left as they were.");
        }

        return back()->with('success', sprintf(
            '%d leave credits saved for %d %s.',
            $year,
            $changed,
            $changed === 1 ? 'employee' : 'employees',
        ));
    }

    /**
     * Copy a year's figures onto another year.
     */
    public function copy(CopyCreditsRequest $request): RedirectResponse
    {
        $from = $request->fromYear();
        $to = $request->year();

        $result = $this->credits->copyFromYear($from, $to, $request->overwrite(), $this->user($request));

        if ($result['copied'] === 0) {
            return back()->with(
                'success',
                $result['skipped'] > 0
                    ? "Every figure {$from} has was already on file for {$to}, so nothing was copied."
                    : "There are no {$from} credits on file to copy.",
            );
        }

        $message = sprintf(
            "%d of %d's figures %s copied to %d, across %d %s.",
            $result['copied'],
            $from,
            $result['copied'] === 1 ? 'was' : 'were',
            $to,
            $result['employees'],
            $result['employees'] === 1 ? 'employee' : 'employees',
        );

        if ($result['skipped'] > 0) {
            $message .= sprintf(
                ' %d already had a figure for %d and %s kept.',
                $result['skipped'],
                $to,
                $result['skipped'] === 1 ? 'was' : 'were',
            );
        }

        return back()->with('success', $message);
    }

    /**
     * Grant the same figures to everyone on a year's sheet.
     */
    public function grant(GrantCreditsRequest $request): RedirectResponse
    {
        $year = $request->integer('year');

        $result = $this->credits->grantToAll(
            $year,
            $request->daysByType(),
            $request->officeId(),
            $request->overwrite(),
            $this->user($request),
        );

        if ($result['granted'] === 0) {
            return back()->with(
                'success',
                $result['skipped'] > 0
                    ? "Everybody already had those {$year} figures on file, so nothing was granted."
                    : "Nobody on the {$year} sheet matched, so nothing was granted.",
            );
        }

        $message = sprintf(
            '%d leave credits granted to %d %s.',
            $year,
            $result['employees'],
            $result['employees'] === 1 ? 'employee' : 'employees',
        );

        if ($result['skipped'] > 0) {
            $message .= sprintf(
                ' %d existing %s kept.',
                $result['skipped'],
                $result['skipped'] === 1 ? 'figure was' : 'figures were',
            );
        }

        return back()->with('success', $message);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * The year being looked at, clamped to the years the picker offers.
     */
    private function year(Request $request): int
    {
        return $this->credits->resolveYear($request->query('year'));
    }

    /**
     * The credits columns.
     *
     * All leave types, retired ones included, for business rule 8's reason: a
     * type HR stopped using halfway through a year still has figures and days
     * against it, and dropping the column would hide a balance rather than
     * retire it. `isActive` travels with each column so the screen can show a
     * retired one as read-only.
     *
     * @return list<array{id: int, code: string, name: string, color: string, isActive: bool}>
     */
    private function leaveTypeColumns(): array
    {
        return array_values($this->tallies->leaveTypes()
            ->map(static fn (LeaveType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'color' => $type->color,
                'isActive' => $type->is_active,
            ])
            ->all());
    }

    /**
     * @param  list<array{id: int, code: string, name: string, color: string, isActive: bool}>  $leaveTypes
     * @return list<int>
     */
    private function columnIds(array $leaveTypes): array
    {
        return array_column($leaveTypes, 'id');
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
                    'value' => $office->id,
                    'label' => $office->name,
                ])
                ->all()
        );
    }

    /**
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
