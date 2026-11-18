<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Signatory;
use App\Models\User;

/**
 * Everyone who prints the tally may see who will sign it; only an HR Admin
 * changes the list, as with every other setting (settings.manage).
 */
class SignatoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function update(User $user, Signatory $signatory): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function delete(User $user, Signatory $signatory): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }

    public function reorder(User $user): bool
    {
        return $user->can(Permission::ManageSettings->value);
    }
}
