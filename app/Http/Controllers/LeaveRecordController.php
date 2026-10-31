<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DayPart;
use App\Http\Requests\Leave\PreviewLeaveDatesRequest;
use App\Http\Requests\Leave\StoreLeaveRecordRequest;
use App\Http\Requests\Leave\UpdateLeaveRecordRequest;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Services\LeaveEncodingService;
use App\Services\LeaveTallyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Encoding leave: the repetitive work this whole system exists to make quicker.
 *
 * The rules live in LeaveEncodingService — weekends and holidays skipped, half
 * days worth 0.5, nothing encoded twice on one date. Conflicts come back as
 * validation errors, so they land on the form field the encoder is looking at
 * and this controller stays thin.
 */
class LeaveRecordController extends Controller
{
    public function __construct(
        private readonly LeaveEncodingService $encoding,
        private readonly LeaveTallyService $tallies,
    ) {}

    /**
     * What saving this range would do, asked while the encoder is still typing.
     *
     * Answered by the same service that does the saving, so the preview can
     * never promise dates the save would refuse. Returns JSON rather than an
     * Inertia page because it is a question about a form, not a navigation.
     */
    public function preview(PreviewLeaveDatesRequest $request): JsonResponse
    {
        $range = $request->range();

        $employee = Employee::query()->findOrFail($range['employee_id']);

        return response()->json($this->encoding->preview(
            $employee,
            Carbon::parse($range['date_from'])->startOfDay(),
            Carbon::parse($range['date_to'])->startOfDay(),
            DayPart::from($range['day_part']),
            $range['ignore_record_id'],
        ));
    }

    public function store(StoreLeaveRecordRequest $request): RedirectResponse
    {
        $created = $this->encoding->store($request->range(), $request->user()?->id);

        $employee = $created[0]->loadMissing('employee')->employee;
        $days = array_sum(array_map(static fn (LeaveRecord $record): float => $record->days(), $created));

        return back()->with('success', sprintf(
            '%s %s saved for %s.',
            $this->tallies->formatDays($days),
            $days === 1.0 ? 'day' : 'days',
            $employee->list_name,
        ));
    }

    public function update(UpdateLeaveRecordRequest $request, LeaveRecord $leaveRecord): RedirectResponse
    {
        $record = $this->encoding->update($leaveRecord, $request->changes());

        return back()->with('success', sprintf(
            '%s updated for %s.',
            $record->leave_date->format('j F Y'),
            $record->loadMissing('employee')->employee->list_name,
        ));
    }

    /**
     * Soft delete, so a day removed by mistake can be restored (business
     * rule 9).
     */
    public function destroy(LeaveRecord $leaveRecord): RedirectResponse
    {
        $this->authorize('delete', $leaveRecord);

        $date = $leaveRecord->leave_date->format('j F Y');
        $employee = $leaveRecord->loadMissing('employee')->employee->list_name;

        $this->encoding->delete($leaveRecord);

        return back()->with('success', sprintf('%s removed from %s\'s tally.', $date, $employee));
    }
}
