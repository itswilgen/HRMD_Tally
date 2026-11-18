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
use App\Services\TallyFilters;
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

    public function __construct(
        private readonly LeaveTallyService $tallies,
        private readonly LeaveEncodingService $encoding,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveRecord::class);

        $filters = TallyFilters::fromRequest($request);
        $year = $filters->year;

        $employees = $filters->employees()
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
            'years' => TallyFilters::selectableYears(),
            'currentMonth' => $this->currentMonth($year),
            'asOf' => Carbon::now()->toDateString(),

            'filters' => $filters->toArray(),
        ]);
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
