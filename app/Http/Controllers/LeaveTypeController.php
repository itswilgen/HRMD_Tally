<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LeaveChipColor;
use App\Http\Requests\LeaveTypes\ReorderLeaveTypesRequest;
use App\Http\Requests\LeaveTypes\StoreLeaveTypeRequest;
use App\Http\Requests\LeaveTypes\UpdateLeaveTypeRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Leave types: the codes HR encodes and the tally prints
 * (business rule 8). Everyone signed in may read the list; only an HR Admin
 * changes it, which LeaveTypePolicy and the requests enforce.
 */
class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveTypeService $leaveTypes) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $rows = LeaveType::query()
            // Two counts in one query for the page, not two per row. Deleted
            // leave records count, to match LeaveType::isInUse exactly: the
            // page must never offer a Delete or a code edit the server refuses.
            ->withCount([
                'leaveRecords' => fn ($query) => $query->withTrashed(),
                'leaveCredits',
            ])
            ->ordered()
            ->get()
            ->map(static function (LeaveType $type): array {
                $records = (int) ($type->leave_records_count ?? 0);
                $credits = (int) ($type->leave_credits_count ?? 0);

                return [
                    'id' => (int) $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'color' => $type->color,
                    'isActive' => $type->is_active,
                    'leaveRecordsCount' => $records,
                    'leaveCreditsCount' => $credits,
                    'inUse' => $records > 0 || $credits > 0,
                ];
            })
            ->all();

        return Inertia::render('settings/leave-types', [
            'leaveTypes' => $rows,
            'colors' => LeaveChipColor::options(),
            'can' => [
                'manage' => $request->user()?->can('create', LeaveType::class) ?? false,
            ],
        ]);
    }

    public function store(StoreLeaveTypeRequest $request): RedirectResponse
    {
        /** @var array{code: string, name: string, color: string} $data */
        $data = $request->validated();

        $type = $this->leaveTypes->create($data, $this->actor($request));

        return back()->with('success', "{$type->code} — {$type->name} has been added at the end of the list.");
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        /** @var array{code: string, name: string, color: string} $data */
        $data = $request->validated();

        $this->leaveTypes->update($leaveType, $data, $this->actor($request));

        return back()->with('success', "{$leaveType->code} has been saved.");
    }

    public function reorder(ReorderLeaveTypesRequest $request): RedirectResponse
    {
        $this->leaveTypes->reorder($request->ids(), $this->actor($request));

        return back()->with('success', 'The new order is saved. Encoding and the tally use it now.');
    }

    public function retire(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('update', $leaveType);

        $this->leaveTypes->setActive($leaveType, false, $this->actor($request));

        return back()->with('success', "{$leaveType->code} is retired. Its records stay; it is no longer offered when encoding.");
    }

    public function restore(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('update', $leaveType);

        $this->leaveTypes->setActive($leaveType, true, $this->actor($request));

        return back()->with('success', "{$leaveType->code} is back on the encoding list.");
    }

    public function destroy(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('delete', $leaveType);

        $code = $leaveType->code;

        $this->leaveTypes->delete($leaveType, $this->actor($request));

        return back()->with('success', "{$code} has been deleted.");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
