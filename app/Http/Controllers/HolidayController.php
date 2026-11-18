<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\HolidayKind;
use App\Http\Requests\Holidays\HolidayRequest;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Holidays: the days encoding skips, one year at a time
 * (PROJECT_CONTEXT section 5.3). Both roles read it; HolidayPolicy and
 * HolidayRequest keep changes to an HR Admin.
 */
class HolidayController extends Controller
{
    private const FIRST_YEAR = 2000;

    private const LAST_YEAR = 2100;

    public function __construct(private readonly HolidayService $holidays) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Holiday::class);

        $year = filter_var($request->query('year'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::FIRST_YEAR, 'max_range' => self::LAST_YEAR],
        ]);

        $year = is_int($year) ? $year : (int) now()->format('Y');

        return Inertia::render('settings/holidays', [
            'year' => $year,
            'currentYear' => (int) now()->format('Y'),
            'yearRange' => ['first' => self::FIRST_YEAR, 'last' => self::LAST_YEAR],
            'holidays' => $this->holidays->forYear($year),
            'kinds' => HolidayKind::options(),
            'can' => [
                'manage' => $request->user()?->can('create', Holiday::class) ?? false,
            ],
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        /** @var array{date: string, name: string, kind: string} $data */
        $data = $request->safe()->only(['date', 'name', 'kind']);

        $holiday = $this->holidays->create($data, $this->actor($request));

        return back()->with('success', $holiday->recurs_yearly
            ? "{$holiday->name} is added, every {$holiday->date->format('F j')}. Encoding skips it from now on."
            : "{$holiday->name} is added on {$holiday->date->format('D, F j, Y')}. Encoding skips it from now on.");
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        /** @var array{date: string, name: string, kind: string} $data */
        $data = $request->safe()->only(['date', 'name', 'kind']);

        $this->holidays->update($holiday, $data, $this->actor($request));

        return back()->with('success', "{$holiday->name} has been saved.");
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorize('delete', $holiday);

        $name = $holiday->name;

        $this->holidays->delete($holiday, $this->actor($request));

        return back()->with('success', "{$name} is removed. That day is a working day again for new leave.");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
