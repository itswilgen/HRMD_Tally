<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User accounts, for HR Admin only. Authorization is UserPolicy's job; this
 * controller only moves data between the request and UserAccountService.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $users = User::query()
            // Eager loaded because the table prints every user's role, and
            // preventLazyLoading() would otherwise fail the request in dev.
            ->with('roles')
            // One subquery for the whole page, not one per row: the table greys
            // out Delete for an account that has already encoded leave.
            ->withCount('encodedLeaveRecords')
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when(
                in_array($role, UserRole::values(), true),
                fn ($query) => $query->role($role),
            )
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderedByName()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('users/index', [
            'users' => UserResource::collection($users),
            'roles' => $this->roleOptions(),
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
            ],
            // Sent so the table can explain, before anyone clicks, why the one
            // remaining administrator cannot be demoted, deactivated or
            // deleted. UserPolicy is still what enforces it.
            'lastActiveAdminId' => $this->lastActiveAdminId(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/create', [
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string, role: string} $data */
        $data = $request->validated();

        $user = $this->accounts->create($data);

        return to_route('users.index')
            ->with('success', "{$user->name}'s account has been created.");
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->loadCount('encodedLeaveRecords');

        return Inertia::render('users/edit', [
            // resolve() rather than toArray(), so that a conditional key added
            // to UserResource later is actually removed instead of being sent
            // as a MissingValue placeholder. See EmployeeController::edit.
            'user' => (new UserResource($user->load('roles')))->resolve($request),
            'roles' => $this->roleOptions(),
            'lastActiveAdminId' => $this->lastActiveAdminId(),
            // The policy itself decides whether each action is offered; the
            // page only turns its decision into a sentence.
            'can' => [
                'changeRole' => $request->user()?->can('changeRole', $user) ?? false,
                'deactivate' => $request->user()?->can('deactivate', $user) ?? false,
                'delete' => $request->user()?->can('delete', $user) ?? false,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        /** @var array{name: string, email: string, role: string, password?: string|null} $data */
        $data = $request->validated();

        // Changing a role has its own rule: you may not demote yourself, and the
        // last active HR Admin may not be demoted at all.
        if ($user->role() !== UserRole::from($data['role'])) {
            $this->authorize('changeRole', $user);
        }

        $this->accounts->update($user, $data);

        return to_route('users.index')
            ->with('success', "{$user->name}'s account has been updated.");
    }

    public function activate(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->accounts->setActive($user, true);

        return back()->with('success', "{$user->name} can sign in again.");
    }

    public function deactivate(User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $this->accounts->setActive($user, false);

        return back()->with('success', "{$user->name} can no longer sign in.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;

        $user->delete();

        return to_route('users.index')
            ->with('success', "{$name}'s account has been deleted.");
    }

    /**
     * The id of the only remaining active HR Admin, or null when there is more
     * than one (or none) — in which case nobody is irreplaceable.
     */
    private function lastActiveAdminId(): ?int
    {
        $admins = User::query()
            ->where('is_active', true)
            ->role(UserRole::HrAdmin->value)
            ->limit(2)
            ->pluck('id');

        return $admins->count() === 1 ? (int) $admins->first() : null;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function roleOptions(): array
    {
        return array_map(static fn (UserRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ], UserRole::cases());
    }
}
