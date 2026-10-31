<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Office;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OfficePolicy
{
    /**
     * The office filter on the tally needs this list, so both roles may read it.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function view(User $user, Office $office): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function update(User $user, Office $office): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    /**
     * Offices are restricted on delete at the database level, so an office with
     * employees cannot go. Saying why beats a foreign key error.
     */
    public function delete(User $user, Office $office): Response
    {
        if (! $user->can(Permission::ManageSettings->value)) {
            return Response::deny();
        }

        return $office->employees()->withTrashed()->exists()
            ? Response::deny("{$office->name} still has employees assigned to it. Move them to another office first.")
            : Response::allow();
    }
}
