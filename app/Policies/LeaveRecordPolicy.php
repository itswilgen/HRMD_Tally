<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LeaveRecord;
use App\Models\User;

/**
 * Encoding leave is the daily work of both roles, so HR Staff have the same
 * rights over leave records as an HR Admin. The admin-only line is drawn around
 * employees and settings, not around the records themselves — an HR Staffer who
 * could not fix a day they had just typed wrong would have to queue behind an
 * admin for every slip.
 */
class LeaveRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function view(User $user, LeaveRecord $leaveRecord): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageLeave->value);
    }

    public function update(User $user, LeaveRecord $leaveRecord): bool
    {
        return $user->can(Permission::ManageLeave->value);
    }

    /**
     * Soft delete only. The row stays recoverable and the activity log keeps
     * who removed it (business rule 9), so this does not need to be rarer than
     * the right to encode in the first place.
     */
    public function delete(User $user, LeaveRecord $leaveRecord): bool
    {
        return $user->can(Permission::ManageLeave->value);
    }

    public function restore(User $user, LeaveRecord $leaveRecord): bool
    {
        return $user->can(Permission::ManageLeave->value);
    }
}
