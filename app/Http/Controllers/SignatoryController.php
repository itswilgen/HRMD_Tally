<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Signatories\SignatoryRequest;
use App\Models\Signatory;
use App\Models\User;
use App\Services\SignatoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Signatories: who signs the printed tally sheet, left to right
 * (PROJECT_CONTEXT 5.7). SignatoryPolicy keeps changes to an HR Admin.
 */
class SignatoryController extends Controller
{
    public function __construct(private readonly SignatoryService $signatories) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Signatory::class);

        return Inertia::render('settings/signatories', [
            'signatories' => Signatory::query()->ordered()->get()
                ->map(static fn (Signatory $signatory): array => [
                    'id' => (int) $signatory->id,
                    'label' => $signatory->label,
                    'name' => $signatory->name,
                    'position' => $signatory->position,
                ])
                ->all(),
            'can' => [
                'manage' => $request->user()?->can('create', Signatory::class) ?? false,
            ],
        ]);
    }

    public function store(SignatoryRequest $request): RedirectResponse
    {
        /** @var array{label: string, name: string, position: string} $data */
        $data = $request->validated();

        $signatory = $this->signatories->create($data, $this->actor($request));

        return back()->with('success', "{$signatory->name} is added. The printed sheet shows them from now on.");
    }

    public function update(SignatoryRequest $request, Signatory $signatory): RedirectResponse
    {
        /** @var array{label: string, name: string, position: string} $data */
        $data = $request->validated();

        $this->signatories->update($signatory, $data, $this->actor($request));

        return back()->with('success', "{$signatory->name} has been saved.");
    }

    public function move(Request $request, Signatory $signatory): RedirectResponse
    {
        $this->authorize('reorder', Signatory::class);

        $validated = $request->validate(['direction' => ['required', 'in:left,right']]);

        $this->signatories->move($signatory, $validated['direction'] === 'left' ? -1 : 1, $this->actor($request));

        return back();
    }

    public function destroy(Request $request, Signatory $signatory): RedirectResponse
    {
        $this->authorize('delete', $signatory);

        $name = $signatory->name;

        $this->signatories->delete($signatory, $this->actor($request));

        return back()->with('success', "{$name} is removed from the printed sheet.");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
