<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LeaveTypePolicy
{
    /**
     * Everyone who encodes leave needs the list of codes, so viewing is not
     * gated behind settings.manage.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function view(User $user, LeaveType $leaveType): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function update(User $user, LeaveType $leaveType): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    /**
     * A code that has ever been used must be deactivated instead of deleted, or
     * historical tally cells would lose the letters printed on the paper sheet.
     */
    public function delete(User $user, LeaveType $leaveType): Response
    {
        if (! $user->can(Permission::ManageSettings->value)) {
            return Response::deny();
        }

        return $leaveType->isInUse()
            ? Response::deny("{$leaveType->code} is already used on leave records. Switch it off instead of deleting it, so past tallies keep their codes.")
            : Response::allow();
    }
}
