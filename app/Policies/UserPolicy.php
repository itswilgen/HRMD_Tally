<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User accounts are HR Admin territory. On top of that this policy exists to
 * stop an administrator locking the office out of its own system: there must
 * always be one active HR Admin left who can get back in.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function update(User $user, User $target): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    /**
     * Changing a role is separated from the rest of the form because demoting
     * yourself is how an office ends up with no administrator at all.
     */
    public function changeRole(User $user, User $target): Response
    {
        if (! $user->can(Permission::ManageUsers->value)) {
            return Response::deny();
        }

        if ($user->is($target)) {
            return Response::deny('You cannot change your own role. Ask another HR Admin to do it.');
        }

        return $this->isLastActiveAdmin($target)
            ? Response::deny("{$target->name} is the only active HR Admin. Promote someone else first.")
            : Response::allow();
    }

    public function deactivate(User $user, User $target): Response
    {
        if (! $user->can(Permission::ManageUsers->value)) {
            return Response::deny();
        }

        if ($user->is($target)) {
            return Response::deny('You cannot deactivate your own account.');
        }

        return $this->isLastActiveAdmin($target)
            ? Response::deny("{$target->name} is the only active HR Admin. Someone else must be able to sign in.")
            : Response::allow();
    }

    public function delete(User $user, User $target): Response
    {
        if (! $user->can(Permission::ManageUsers->value)) {
            return Response::deny();
        }

        if ($user->is($target)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($this->isLastActiveAdmin($target)) {
            return Response::deny("{$target->name} is the only active HR Admin. Promote someone else first.");
        }

        return $target->encodedLeaveRecords()->withTrashed()->exists()
            ? Response::deny("{$target->name} has encoded leave records. Deactivate the account instead, so the activity log keeps a name against them.")
            : Response::allow();
    }

    private function isLastActiveAdmin(User $target): bool
    {
        if (! $target->is_active || ! $target->isHrAdmin()) {
            return false;
        }

        return User::query()
            ->whereKeyNot($target->getKey())
            ->where('is_active', true)
            ->role(UserRole::HrAdmin->value)
            ->doesntExist();
    }
}
