<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    /**
     * Encoding a date range needs the holiday list to skip over, so both roles
     * may read it.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }
}
