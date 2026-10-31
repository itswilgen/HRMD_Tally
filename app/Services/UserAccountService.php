<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing user accounts.
 *
 * The role lives in a pivot table while the rest of the account lives on the
 * users row, so each of these methods wraps both writes in one transaction: an
 * account that exists without a role can see nothing and would have to be
 * repaired by hand.
 */
class UserAccountService
{
    /**
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->syncRoles([$data['role']]);

            return $user;
        });
    }

    /**
     * A blank password means "leave it alone", so an HR Admin can fix a typo in
     * a colleague's name without having to invent a new password for them.
     *
     * @param  array{name: string, email: string, role: string, password?: string|null}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (isset($data['password']) && $data['password'] !== '') {
                $user->password = $data['password'];
            }

            $user->save();

            $previousRole = $user->role();
            $newRole = UserRole::from($data['role']);

            if ($previousRole !== $newRole) {
                $user->syncRoles([$newRole->value]);

                // Roles live in a pivot table, which the model's own activity
                // log does not watch, so the change is recorded here.
                activity('user')
                    ->performedOn($user)
                    ->withProperties([
                        'attributes' => ['role' => $newRole->value],
                        'old' => ['role' => $previousRole?->value],
                    ])
                    ->log('role changed');
            }

            return $user;
        });
    }

    /**
     * Deactivating keeps the account and everything it encoded; it only takes
     * away the ability to sign in.
     */
    public function setActive(User $user, bool $isActive): User
    {
        $user->is_active = $isActive;
        $user->save();

        return $user;
    }
}
