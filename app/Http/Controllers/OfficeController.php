<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Offices\StoreOfficeRequest;
use App\Http\Requests\Offices\UpdateOfficeRequest;
use App\Models\Office;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offices, which every employee belongs to exactly one of.
 *
 * An office is a name and nothing else, so there is no service: the one rule
 * worth having — you cannot remove an office people are still assigned to —
 * lives in OfficePolicy, where it can explain itself to the person who tried.
 */
class OfficeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Office::class);

        $offices = Office::query()
            // The list greys out Delete for an office that still has people in
            // it, which is one subquery for the page rather than one per row.
            // withTrashed to match OfficePolicy::delete exactly: counting only
            // live employees would offer a button the policy then refuses.
            ->withCount(['employees' => fn ($query) => $query->withTrashed()])
            ->orderBy('name')
            ->get()
            ->map(static fn (Office $office): array => [
                'id' => (int) $office->id,
                'name' => $office->name,
                'employeesCount' => (int) ($office->employees_count ?? 0),
            ])
            ->all();

        return Inertia::render('settings/offices', [
            'offices' => $offices,
            'can' => [
                'manage' => $request->user()?->can('create', Office::class) ?? false,
            ],
        ]);
    }

    public function store(StoreOfficeRequest $request): RedirectResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();

        $office = Office::query()->create($data);

        return back()->with('success', "{$office->name} has been added.");
    }

    public function update(UpdateOfficeRequest $request, Office $office): RedirectResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();

        $office->update($data);

        return back()->with('success', "{$office->name} has been renamed.");
    }

    public function destroy(Office $office): RedirectResponse
    {
        $this->authorize('delete', $office);

        $name = $office->name;

        $office->delete();

        return back()->with('success', "{$name} has been removed.");
    }
}
