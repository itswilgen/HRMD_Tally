<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LeaveCredit;
use App\Models\User;

/**
 * Leave credits: everyone reads, only an HR Admin writes.
 *
 * The same shape as OfficePolicy, and for the same kind of reason. The credits
 * screen is something HR Staff need while encoding — it says how much of an
 * entitlement is left — but the figure itself is copied off a leave card by the
 * one pair of hands HR wanted responsible for it.
 *
 * Every write action maps to manage() rather than to create/update/delete
 * separately: saving the grid creates some rows, overwrites others and clears
 * the rest in a single request, so splitting the question three ways would ask
 * it three times about one action and answer it the same way each time.
 */
class LeaveCreditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewCredits->value);
    }

    public function view(User $user, LeaveCredit $leaveCredit): bool
    {
        return $user->can(Permission::ViewCredits->value);
    }

    /**
     * Typing a figure, copying a year, granting to everyone, importing a sheet.
     */
    public function manage(User $user): bool
    {
        return $user->can(Permission::ManageCredits->value);
    }
}
