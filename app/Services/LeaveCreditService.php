<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave credits: the figure HR grants, against the days actually taken.
 *
 * Phase 1 does not earn credits. An HR Admin types what the leave card says —
 * for this year or any year already on file — and this service puts that figure
 * next to the leave the employee took, so the screen can say
 * "15 granted − 4.5 taken = 10.5 left".
 *
 * The "taken" half is never summed here. It comes from
 * LeaveTallyService::totalsForEmployees(), which is the one place a leave total
 * is built (business rule 6), and the formatting comes from the same service's
 * formatDays(), so a balance cannot read 10.5 on the credits screen and 10.50
 * anywhere else.
 *
 * A balance is allowed to go negative. HR encodes approved leave, and an
 * employee who has taken more than they were granted is a fact the screen must
 * show rather than a save it must refuse; nothing here gates encoding.
 *
 * @phpstan-type CreditCell array{credits: float|null, creditsText: string, used: float, usedText: string, remaining: float|null, remainingText: string, isOverdrawn: bool}
 * @phpstan-type CreditRow array{cells: array<int, CreditCell>, creditsTotal: float, creditsTotalText: string, usedTotal: float, usedTotalText: string, remainingTotal: float, remainingTotalText: string, hasCredits: bool, isOverdrawn: bool}
 */
class LeaveCreditService
{
    /** How many rows go in one upsert when filling a whole year at once. */
    private const UPSERT_CHUNK = 500;

    /** How far back the year picker will reach even if older data turns up. */
    private const MAX_YEARS_BACK = 30;

    /** The window offered before the system has any data at all. */
    private const DEFAULT_YEARS_BACK = 5;

    /**
     * Leave codes keyed by id, read once per request for the activity log.
     *
     * @var array<int, string>|null
     */
    private ?array $codes = null;

    public function __construct(private readonly LeaveTallyService $tallies) {}

    /**
     * Credits, days taken and what is left, for a page of employees.
     *
     * Two queries for the page however many employees are on it: one for the
     * credit rows, one for the leave totals.
     *
     * @param  list<int>  $employeeIds
     * @param  array<int, int>  $leaveTypeIds  the columns to build, in display order
     * @return array<int, CreditRow>
     */
    public function forEmployees(array $employeeIds, int $year, array $leaveTypeIds): array
    {
        $used = $this->tallies->totalsForEmployees($employeeIds, $year);
        $credits = $this->creditsByEmployee($employeeIds, $year);

        $rows = [];

        foreach ($employeeIds as $employeeId) {
            $rows[$employeeId] = $this->buildRow(
                $credits[$employeeId] ?? [],
                $used[$employeeId] ?? [],
                $leaveTypeIds,
            );
        }

        return $rows;
    }

    /**
     * One employee's figures for one year, as the per-employee screen reads
     * them and as its form is seeded.
     *
     * @param  array<int, int>  $leaveTypeIds
     * @return CreditRow
     */
    public function forEmployee(Employee $employee, int $year, array $leaveTypeIds): array
    {
        return $this->forEmployees([$employee->id], $year, $leaveTypeIds)[$employee->id];
    }

    /**
     * Save one employee's figures for one year.
     *
     * The map is leave type id => days, where **null means "no figure"** and
     * removes the row. That distinction is the whole contract: a blank cell and
     * a nought are different answers, because nought days of VL is something HR
     * decided and a blank is something HR has not got to yet.
     *
     * A figure that has not moved is not written and not logged, so saving the
     * grid after changing one cell leaves one entry in the log rather than
     * twenty-five.
     *
     * @param  array<int, float|null>  $daysByType
     * @return bool whether anything actually changed
     */
    public function save(Employee $employee, int $year, array $daysByType, User $actor): bool
    {
        return DB::transaction(fn (): bool => $this->apply($employee, $year, $daysByType, $actor));
    }

    /**
     * Save the credits grid: many employees, one year, one transaction.
     *
     * @param  array<int, array<int, float|null>>  $daysByEmployee  employee id => (leave type id => days)
     * @return int how many employees changed
     */
    public function saveMany(array $daysByEmployee, int $year, User $actor): int
    {
        if ($daysByEmployee === []) {
            return 0;
        }

        $employees = Employee::query()
            ->whereIn('id', array_keys($daysByEmployee))
            ->get();

        return DB::transaction(function () use ($employees, $daysByEmployee, $year, $actor): int {
            $changed = 0;

            foreach ($employees as $employee) {
                if ($this->apply($employee, $year, $daysByEmployee[$employee->id] ?? [], $actor)) {
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /**
     * Copy a year's figures onto another year.
     *
     * The standard yearly grant rarely changes, so last year's sheet is the
     * fastest honest starting point for this one. Without $overwrite, an
     * employee who already has a figure for a leave type in the target year
     * keeps it: copying is for filling blanks, and quietly overwriting a figure
     * HR had already corrected would be the one thing this must not do.
     *
     * @return array{copied: int, skipped: int, employees: int}
     */
    public function copyFromYear(int $from, int $to, bool $overwrite, User $actor): array
    {
        $source = LeaveCredit::query()
            ->forYear($from)
            ->get(['employee_id', 'leave_type_id', 'days']);

        if ($source->isEmpty()) {
            return ['copied' => 0, 'skipped' => 0, 'employees' => 0];
        }

        $existing = $overwrite ? [] : $this->existingKeys($to);

        $values = [];
        $skipped = 0;
        $employees = [];

        foreach ($source as $credit) {
            $key = $credit->employee_id.':'.$credit->leave_type_id;

            if (isset($existing[$key])) {
                $skipped++;

                continue;
            }

            $values[] = [
                'employee_id' => $credit->employee_id,
                'leave_type_id' => $credit->leave_type_id,
                'year' => $to,
                'days' => $credit->days,
                'granted_by' => $actor->id,
            ];

            $employees[$credit->employee_id] = true;
        }

        $copied = $this->upsert($values);

        if ($copied > 0) {
            activity('leave_credit')
                ->causedBy($actor)
                ->event('created')
                ->withProperties([
                    'from_year' => $from,
                    'year' => $to,
                    'copied' => $copied,
                    'skipped' => $skipped,
                    'overwrite' => $overwrite,
                ])
                ->log(sprintf(
                    'Copied %d leave credit %s from %d to %d',
                    $copied,
                    $copied === 1 ? 'figure' : 'figures',
                    $from,
                    $to,
                ));
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'employees' => count($employees)];
    }

    /**
     * Grant the same figures to everyone on a year's sheet.
     *
     * "Everyone" is the tally sheet's own idea of who is on a year: anyone
     * currently active, plus anyone who has leave on file for that year, so
     * back-filling 2024 reaches the people who were working in 2024 rather than
     * the people working today.
     *
     * The map it is given is the same shape every credits form produces, where
     * null means "left blank". A blank is not a grant of nought days here: the
     * dialog asks "give everybody this much", so a leave type the HR Admin did
     * not fill in is one they are not granting, and it is dropped rather than
     * written as a nought across the whole office.
     *
     * @param  array<int, float|null>  $daysByType
     * @return array{granted: int, skipped: int, employees: int}
     */
    public function grantToAll(int $year, array $daysByType, ?int $officeId, bool $overwrite, User $actor): array
    {
        $figures = [];

        foreach ($daysByType as $leaveTypeId => $days) {
            if ($days !== null) {
                $figures[$leaveTypeId] = $days;
            }
        }

        if ($figures === []) {
            return ['granted' => 0, 'skipped' => 0, 'employees' => 0];
        }

        $employeeIds = $this->employeesOnYear($year, $officeId);

        if ($employeeIds === []) {
            return ['granted' => 0, 'skipped' => 0, 'employees' => 0];
        }

        $existing = $overwrite ? [] : $this->existingKeys($year);

        $values = [];
        $skipped = 0;
        $touched = [];

        foreach ($employeeIds as $employeeId) {
            foreach ($figures as $leaveTypeId => $days) {
                if (isset($existing[$employeeId.':'.$leaveTypeId])) {
                    $skipped++;

                    continue;
                }

                $values[] = [
                    'employee_id' => $employeeId,
                    'leave_type_id' => $leaveTypeId,
                    'year' => $year,
                    'days' => $days,
                    'granted_by' => $actor->id,
                ];

                $touched[$employeeId] = true;
            }
        }

        $granted = $this->upsert($values);

        if ($granted > 0) {
            activity('leave_credit')
                ->causedBy($actor)
                ->event('created')
                ->withProperties([
                    'year' => $year,
                    'office_id' => $officeId,
                    'granted' => $granted,
                    'skipped' => $skipped,
                    'employees' => count($touched),
                    'overwrite' => $overwrite,
                    'days' => $this->byCode($figures),
                ])
                ->log(sprintf(
                    'Granted %d leave credit %s for %d to %d %s',
                    $granted,
                    $granted === 1 ? 'figure' : 'figures',
                    $year,
                    count($touched),
                    count($touched) === 1 ? 'employee' : 'employees',
                ));
        }

        return ['granted' => $granted, 'skipped' => $skipped, 'employees' => count($touched)];
    }

    /**
     * Remove every credit row belonging to these employees.
     *
     * Called when an employee record is erased for good. The rows are
     * restrictOnDelete, so they have to go first, and erasing only ever reaches
     * someone with no leave on file — which means no balance anyone has read.
     *
     * @param  list<int>  $employeeIds
     */
    public function forgetEmployees(array $employeeIds): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        return LeaveCredit::query()->whereIn('employee_id', $employeeIds)->delete();
    }

    /**
     * The years the picker offers, newest first.
     *
     * Unlike the tally's fixed five-year window, this reaches back as far as
     * there is data, because back-filling an old year is the point of the
     * feature: a year HR has leave on file for is a year they may need to put
     * credits against. Next year is offered for the same reason the tally does —
     * leave, and so a grant, is sometimes filed in advance.
     *
     * @return list<int>
     */
    public function selectableYears(): array
    {
        $current = Carbon::now()->year;

        $earliestLeave = LeaveRecord::query()->min('leave_date');
        $earliestCredit = LeaveCredit::query()->min('year');

        $candidates = [$current - self::DEFAULT_YEARS_BACK];

        if (is_string($earliestLeave) && $earliestLeave !== '') {
            $candidates[] = (int) Carbon::parse($earliestLeave)->year;
        }

        if (is_numeric($earliestCredit)) {
            $candidates[] = (int) $earliestCredit;
        }

        $earliest = max(min($candidates), $current - self::MAX_YEARS_BACK);

        return range($current + 1, $earliest);
    }

    /**
     * The year a request asked for, or this year when it asked for one the
     * picker does not offer. Every credits screen and download reads its year
     * through here, so a hand-typed ?year=1900 lands on the same sheet
     * everywhere.
     */
    public function resolveYear(mixed $requested): int
    {
        $current = Carbon::now()->year;
        $year = is_numeric($requested) ? (int) $requested : $current;

        return in_array($year, $this->selectableYears(), true) ? $year : $current;
    }

    /**
     * Who is on a year's credits sheet: the tally's rule (business rule 7),
     * plus anyone with a credit on file for that year, so a grant HR typed for
     * someone since resigned does not quietly disappear from the screen and
     * the download that hold it.
     *
     * @return Builder<Employee>
     */
    public function employeesOnSheet(int $year): Builder
    {
        return Employee::query()->where(function (Builder $query) use ($year): void {
            $query->where('is_active', true)
                ->orWhereHas('leaveRecords', fn (Builder $records) => $records->forYear($year))
                ->orWhereHas('leaveCredits', fn (Builder $credits) => $credits->forYear($year));
        });
    }

    /**
     * Writes one employee's figures without opening its own transaction, so the
     * grid can put a hundred of them inside one.
     *
     * @param  array<int, float|null>  $daysByType
     */
    private function apply(Employee $employee, int $year, array $daysByType, User $actor): bool
    {
        if ($daysByType === []) {
            return false;
        }

        $existing = LeaveCredit::query()
            ->where('employee_id', $employee->id)
            ->forYear($year)
            ->get()
            ->keyBy('leave_type_id');

        /** @var array<int, float|null> $old */
        $old = [];
        /** @var array<int, float|null> $new */
        $new = [];

        foreach ($daysByType as $leaveTypeId => $days) {
            /** @var LeaveCredit|null $current */
            $current = $existing->get($leaveTypeId);

            if ($days === null) {
                if ($current === null) {
                    continue;
                }

                $old[$leaveTypeId] = $current->days;
                $new[$leaveTypeId] = null;

                $current->delete();

                continue;
            }

            if ($current === null) {
                $old[$leaveTypeId] = null;
                $new[$leaveTypeId] = $days;

                LeaveCredit::query()->create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveTypeId,
                    'year' => $year,
                    'days' => $days,
                    'granted_by' => $actor->id,
                ]);

                continue;
            }

            // Halves are exact in floating point, but the value arrives as a
            // string from a form and comes back from PostgreSQL as a numeric,
            // so the comparison is made on the figure rather than on the bits.
            if ($this->same($current->days, $days)) {
                continue;
            }

            $old[$leaveTypeId] = $current->days;
            $new[$leaveTypeId] = $days;

            $current->days = $days;
            $current->granted_by = $actor->id;
            $current->save();
        }

        if ($new === []) {
            return false;
        }

        activity('leave_credit')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('updated')
            ->withProperties([
                'year' => $year,
                'attributes' => $this->byCode($new),
                'old' => $this->byCode($old),
            ])
            ->log(sprintf('Set %d leave credits for %s', $year, $employee->list_name));

        return true;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<int, float>>
     */
    private function creditsByEmployee(array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $credits = [];

        $rows = LeaveCredit::query()
            ->forEmployees($employeeIds)
            ->forYear($year)
            ->get(['employee_id', 'leave_type_id', 'days']);

        foreach ($rows as $row) {
            $credits[$row->employee_id][$row->leave_type_id] = $row->days;
        }

        return $credits;
    }

    /**
     * @param  array<int, float>  $credits
     * @param  array<int, float>  $used
     * @param  array<int, int>  $leaveTypeIds
     * @return CreditRow
     */
    private function buildRow(array $credits, array $used, array $leaveTypeIds): array
    {
        $cells = [];

        $creditsTotal = 0.0;
        $usedTotal = 0.0;
        $remainingTotal = 0.0;
        $hasCredits = false;
        $isOverdrawn = false;

        foreach ($leaveTypeIds as $leaveTypeId) {
            $granted = $credits[$leaveTypeId] ?? null;
            $taken = $used[$leaveTypeId] ?? 0.0;

            $usedTotal += $taken;

            $remaining = $granted === null ? null : round($granted - $taken, 1);

            if ($granted !== null) {
                $hasCredits = true;
                $creditsTotal += $granted;
                $remainingTotal += $remaining ?? 0.0;
            }

            $overdrawn = $remaining !== null && $remaining < 0.0;
            $isOverdrawn = $isOverdrawn || $overdrawn;

            $cells[$leaveTypeId] = [
                'credits' => $granted,
                'creditsText' => $granted === null ? '' : $this->figure($granted),
                'used' => $taken,
                'usedText' => $this->figure($taken),
                'remaining' => $remaining,
                'remainingText' => $remaining === null ? '' : $this->figure($remaining),
                'isOverdrawn' => $overdrawn,
            ];
        }

        $creditsTotal = round($creditsTotal, 1);
        $usedTotal = round($usedTotal, 1);
        $remainingTotal = round($remainingTotal, 1);

        return [
            'cells' => $cells,
            'creditsTotal' => $creditsTotal,
            'creditsTotalText' => $this->figure($creditsTotal),
            'usedTotal' => $usedTotal,
            'usedTotalText' => $this->figure($usedTotal),

            // The sum of the balances that exist, not creditsTotal − usedTotal:
            // leave taken against a type with no figure is not a balance, and
            // subtracting it from the types that do have one would invent a
            // shortfall out of a blank cell.
            'remainingTotal' => $remainingTotal,
            'remainingTotalText' => $hasCredits ? $this->figure($remainingTotal) : '',
            'hasCredits' => $hasCredits,
            'isOverdrawn' => $isOverdrawn,
        ];
    }

    /**
     * A figure on the credits screen, where nought is an answer.
     *
     * LeaveTallyService::formatDays() writes 0 as an empty string, which is
     * right on the tally — the paper sheet leaves a month with no leave blank.
     * Here a blank means "HR has not set a figure" and nought means "HR set it
     * to nought", so the two cannot share a rendering. Everything else, half
     * days and the minus sign included, is formatted by that same method.
     */
    public function figure(float $value): string
    {
        return $value === 0.0 ? '0' : $this->tallies->formatDays($value);
    }

    /**
     * The (employee, leave type) pairs that already have a figure for a year.
     *
     * @return array<string, true>
     */
    private function existingKeys(int $year): array
    {
        $keys = [];

        LeaveCredit::query()
            ->forYear($year)
            ->toBase()
            ->select(['employee_id', 'leave_type_id'])
            ->orderBy('employee_id')
            ->each(function (mixed $row) use (&$keys): void {
                $keys[data_get($row, 'employee_id').':'.data_get($row, 'leave_type_id')] = true;
            });

        return $keys;
    }

    /**
     * Who is on a year's sheet, using the tally's own rule so the two screens
     * never disagree about which employees a year has.
     *
     * @return list<int>
     */
    private function employeesOnYear(int $year, ?int $officeId): array
    {
        return array_values(Employee::query()
            ->when($officeId !== null, fn (Builder $query) => $query->where('office_id', $officeId))
            ->where(function (Builder $query) use ($year): void {
                $query->where('is_active', true)
                    ->orWhereHas('leaveRecords', fn (Builder $records) => $records->forYear($year));
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<array<string, mixed>>  $values
     */
    private function upsert(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::UPSERT_CHUNK) as $chunk) {
            LeaveCredit::query()->upsert(
                $chunk,
                ['employee_id', 'leave_type_id', 'year'],
                ['days', 'granted_by', 'updated_at'],
            );
        }

        return count($values);
    }

    /**
     * Re-key a leave-type-id map by leave code, so an activity log entry reads
     * "VL: 15" rather than "7: 15" and still makes sense after a leave type has
     * been renamed or retired.
     *
     * @param  array<int, float|null>  $daysByType
     * @return array<string, float|null>
     */
    private function byCode(array $daysByType): array
    {
        $codes = $this->codes();

        $byCode = [];

        foreach ($daysByType as $leaveTypeId => $days) {
            $byCode[$codes[$leaveTypeId] ?? (string) $leaveTypeId] = $days;
        }

        return $byCode;
    }

    /**
     * @return array<int, string>
     */
    private function codes(): array
    {
        return $this->codes ??= LeaveType::query()
            ->pluck('code', 'id')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }

    /**
     * Two day figures that mean the same thing, within half a tenth.
     */
    private function same(float $left, float $right): bool
    {
        return abs($left - $right) < 0.05;
    }
}
