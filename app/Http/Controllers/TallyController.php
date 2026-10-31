<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DayPart;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Services\LeaveEncodingService;
use App\Services\LeaveTallyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The yearly tally sheet: the page that replaces the paper one.
 *
 * The controller only reads filters and assembles props. Every figure and
 * every cell's text comes from LeaveTallyService, so the screen cannot drift
 * from the PDF and the Excel export added later (business rule 6).
 */
class TallyController extends Controller
{
    /**
     * A page of the sheet. Kept near the paper original's section length, so
     * the whole page stays on screen while the encoder works down it.
     */
    private const PER_PAGE = 25;

    /**
     * How far back the year picker reaches. The system has no leave before it
     * was installed, so offering 1990 would only invite a mis-click; next year
     * is offered because leave is sometimes filed in advance.
     */
    private const YEARS_BACK = 5;

    public function __construct(
        private readonly LeaveTallyService $tallies,
        private readonly LeaveEncodingService $encoding,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveRecord::class);

        $year = $this->year($request);
        $search = trim((string) $request->query('search', ''));
        $letter = mb_strtoupper(trim((string) $request->query('letter', '')));
        $officeId = (int) $request->query('office', 0);

        // A single letter only. Anything else is someone editing the URL, and
        // the honest answer to that is to ignore it rather than to error.
        $letter = preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '';

        $employees = Employee::query()
            ->with('office:id,name')
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($letter !== '', fn (Builder $query) => $query->startingWith($letter))
            ->when($officeId > 0, fn (Builder $query) => $query->where('office_id', $officeId))
            // Business rule 7: the sheet lists the people currently being
            // encoded for, plus anyone who has leave on file for the year being
            // looked at. That second half is what keeps a resigned employee's
            // days visible on the year they were still working.
            ->where(function (Builder $query) use ($year): void {
                $query->where('is_active', true)
                    ->orWhereHas('leaveRecords', fn (Builder $records) => $records->forYear($year));
            })
            ->orderedByName()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $employeeIds = array_values($employees->getCollection()
            ->map(static fn (Employee $employee): int => $employee->id)
            ->all());

        return Inertia::render('tally/index', [
            'employees' => EmployeeResource::collection($employees),

            // Keyed by employee id: one lookup per row on the React side, and
            // the row itself stays the plain employee resource every other
            // page already uses.
            'tally' => $this->tallies->forEmployees($employeeIds, $year),

            // The tally columns, including retired types that still hold days
            // this year (business rule 8).
            'leaveTypes' => $this->leaveTypeColumns(),

            // What may be chosen when encoding: active types only.
            'encodableLeaveTypes' => $this->encoding->leaveTypeOptions(),
            'dayParts' => DayPart::options(),

            'offices' => $this->officeOptions(),
            'letters' => $this->lettersInUse(),

            'year' => $year,
            'years' => $this->selectableYears(),
            'currentMonth' => $this->currentMonth($year),
            'asOf' => Carbon::now()->toDateString(),

            'filters' => [
                'search' => $search,
                'letter' => $letter,
                'office' => $officeId > 0 ? (string) $officeId : '',
            ],
        ]);
    }

    /**
     * The year being looked at, clamped to the years the picker offers so a
     * hand-edited URL cannot ask for a sheet that is not on the list.
     */
    private function year(Request $request): int
    {
        $years = $this->selectableYears();
        $requested = (int) $request->query('year', (string) Carbon::now()->year);

        return in_array($requested, $years, true) ? $requested : Carbon::now()->year;
    }

    /**
     * @return list<int>
     */
    private function selectableYears(): array
    {
        $current = Carbon::now()->year;

        return range($current + 1, $current - self::YEARS_BACK);
    }

    /**
     * The month to accent, or null when the sheet on screen is not this year —
     * there is no "now" on last year's sheet, and highlighting November on it
     * would say something untrue.
     */
    private function currentMonth(int $year): ?int
    {
        $now = Carbon::now();

        return $year === $now->year ? $now->month : null;
    }

    /**
     * @return list<array{id: int, code: string, name: string, color: string}>
     */
    private function leaveTypeColumns(): array
    {
        return array_values($this->tallies->leaveTypes()
            ->map(static fn (LeaveType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'color' => $type->color,
            ])
            ->all());
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
     * Which initial letters actually have employees behind them, so the A-Z
     * tabs can grey out the rest instead of offering an empty sheet.
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
