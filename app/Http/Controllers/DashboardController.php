<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\Office;
use App\Models\User;
use App\Services\LeaveTallyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen after signing in: what is happening today, in facts.
 *
 * Who is out, how much leave this month has, and what was encoded last —
 * every figure read from the records themselves. Anyone without the tally
 * permission gets the page without the leave lists: those name employees.
 */
class DashboardController extends Controller
{
    /** Enough to see the day at a glance; the tally sheet has the rest. */
    private const ON_LEAVE_LIMIT = 12;

    private const RECENT_LIMIT = 8;

    public function __construct(private readonly LeaveTallyService $tallies) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canSeeLeave = $user instanceof User && $user->can('viewAny', LeaveRecord::class);

        $today = Carbon::today();

        $monthDays = $canSeeLeave
            ? $this->tallies->daysBetween($today->copy()->startOfMonth(), $today->copy()->endOfMonth())
            : null;

        return Inertia::render('dashboard', [
            'today' => $today->toDateString(),

            'facts' => [
                'monthDays' => $monthDays,
                // formatDays() leaves 0 blank, as the paper sheet does; a
                // dashboard figure of nothing is still a figure.
                'monthDaysText' => $monthDays === null ? null : ($monthDays === 0.0 ? '0' : $this->tallies->formatDays($monthDays)),
                'activeEmployees' => Employee::query()->where('is_active', true)->count(),
                'offices' => Office::query()->count(),
            ],

            'onLeaveToday' => $canSeeLeave ? $this->onLeave($today) : null,
            'recent' => $canSeeLeave ? $this->recent() : null,
        ]);
    }

    /**
     * @return array{total: int, people: list<array{id: int, name: string, office: string|null, code: string, color: string, dayPart: string}>}
     */
    private function onLeave(Carbon $today): array
    {
        $query = LeaveRecord::query()->whereDate('leave_date', $today);

        $records = (clone $query)
            ->with(['employee:id,last_name,first_name,middle_name,suffix,office_id', 'employee.office:id,name', 'leaveType:id,code,color'])
            ->orderBy('employee_id')
            ->limit(self::ON_LEAVE_LIMIT)
            ->get();

        return [
            'total' => $query->count(),
            'people' => array_values($records->map(static fn (LeaveRecord $record): array => [
                'id' => $record->id,
                'name' => $record->employee->list_name,
                'office' => $record->employee->office?->name,
                'code' => $record->leaveType->code,
                'color' => $record->leaveType->color,
                'dayPart' => $record->day_part->value,
            ])->all()),
        ];
    }

    /**
     * @return list<array{id: int, name: string, code: string, color: string, date: string, dayPart: string, encodedBy: string|null, encodedAt: string|null}>
     */
    private function recent(): array
    {
        return array_values(LeaveRecord::query()
            ->with(['employee:id,last_name,first_name,middle_name,suffix', 'leaveType:id,code,color', 'encoder:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(static fn (LeaveRecord $record): array => [
                'id' => $record->id,
                'name' => $record->employee->list_name,
                'code' => $record->leaveType->code,
                'color' => $record->leaveType->color,
                'date' => $record->leave_date->toDateString(),
                'dayPart' => $record->day_part->value,
                'encodedBy' => $record->encoder?->name,
                'encodedAt' => $record->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
