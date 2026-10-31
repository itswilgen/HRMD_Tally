<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LeaveRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Who may reach the screens at all
|--------------------------------------------------------------------------
*/

it('shows the account list to an HR Admin', function (): void {
    $admin = hrAdmin(['name' => 'Ana Admin']);

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('users/index')
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Ana Admin')
            ->has('roles', 2)
        );
});

it('refuses every account route to HR Staff', function (string $method, string $route): void {
    $staff = hrStaff();
    $target = hrAdmin();

    $url = in_array($route, ['users.index', 'users.create', 'users.store'], true)
        ? route($route)
        : route($route, $target);

    $this->actingAs($staff)->{$method}($url)->assertForbidden();
})->with([
    ['get', 'users.index'],
    ['get', 'users.create'],
    ['post', 'users.store'],
    ['get', 'users.edit'],
    ['patch', 'users.update'],
    ['patch', 'users.activate'],
    ['patch', 'users.deactivate'],
    ['delete', 'users.destroy'],
]);

it('sends guests to the sign-in page', function (): void {
    $this->get(route('users.index'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Listing, searching and filtering
|--------------------------------------------------------------------------
*/

it('finds an account by part of a name or email address', function (): void {
    $admin = hrAdmin(['name' => 'Ana Admin', 'email' => 'ana@hrmd.test']);
    hrStaff(['name' => 'Benito Reyes', 'email' => 'benito@hrmd.test']);

    $this->actingAs($admin)
        ->get(route('users.index', ['search' => 'reyes']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Benito Reyes')
            ->where('filters.search', 'reyes')
        );

    $this->actingAs($admin)
        ->get(route('users.index', ['search' => 'ANA@HRMD']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Ana Admin')
        );
});

it('filters the list by role and by status', function (): void {
    $admin = hrAdmin(['name' => 'Ana Admin']);
    $staff = hrStaff(['name' => 'Benito Reyes']);
    $staff->update(['is_active' => false]);

    $this->actingAs($admin)
        ->get(route('users.index', ['role' => UserRole::HrStaff->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Benito Reyes')
        );

    $this->actingAs($admin)
        ->get(route('users.index', ['status' => 'inactive']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.isActive', false)
        );

    $this->actingAs($admin)
        ->get(route('users.index', ['status' => 'active']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Ana Admin')
        );
});

it('ignores a role filter that is not a real role', function (): void {
    $admin = hrAdmin();
    hrStaff();

    $this->actingAs($admin)
        ->get(route('users.index', ['role' => 'mayor']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 2));
});

it('paginates the list', function (): void {
    $admin = hrAdmin();
    User::factory()->count(20)->create()->each->syncRoles([UserRole::HrStaff->value]);

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 15)
            ->where('users.meta.total', 21)
            ->has('users.links')
        );
});

it('tells the list which account is the only administrator left', function (): void {
    $admin = hrAdmin();
    hrStaff();

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lastActiveAdminId', $admin->id));
});

it('reports no irreplaceable administrator once there are two', function (): void {
    $admin = hrAdmin();
    hrAdmin();

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lastActiveAdminId', null));
});

it('counts the leave records an account encoded', function (): void {
    $admin = hrAdmin();
    $encoder = hrStaff(['name' => 'Benito Reyes']);

    LeaveRecord::factory()->count(2)->create(['encoded_by' => $encoder->id]);

    $this->actingAs($admin)
        ->get(route('users.index', ['search' => 'reyes']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data.0.encodedLeaveRecordsCount', 2)
        );
});

/**
 * Roles and the encoded-record count are both eager loaded, so the page costs
 * the same number of queries whether it shows one account or fifteen. This is
 * the N+1 guard: `preventLazyLoading()` would throw outright, and this notices
 * a per-row subquery that does not throw.
 */
it('reads the account list in a constant number of queries', function (): void {
    $admin = hrAdmin();

    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // One request first, so the permission cache is warm and not counted.
    $countQueries();

    $withOneAccount = $countQueries();

    User::factory()->count(12)->create()->each->syncRoles([UserRole::HrStaff->value]);

    expect($countQueries())->toBe($withOneAccount);
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates an account with a role, active and able to sign in', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Carla Dizon',
            'email' => 'carla.dizon@hrmd.test',
            'role' => UserRole::HrStaff->value,
            'password' => 'Tally-Sheet-2026',
            'password_confirmation' => 'Tally-Sheet-2026',
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('success', "Carla Dizon's account has been created.");

    $created = User::query()->where('email', 'carla.dizon@hrmd.test')->sole();

    expect($created->is_active)->toBeTrue()
        ->and($created->role())->toBe(UserRole::HrStaff)
        ->and(Hash::check('Tally-Sheet-2026', $created->password))->toBeTrue();
});

it('rejects an account with a duplicate email address', function (): void {
    $admin = hrAdmin(['email' => 'ana@hrmd.test']);

    $this->actingAs($admin)
        ->from(route('users.create'))
        ->post(route('users.store'), [
            'name' => 'Someone Else',
            'email' => 'ana@hrmd.test',
            'role' => UserRole::HrStaff->value,
            'password' => 'Tally-Sheet-2026',
            'password_confirmation' => 'Tally-Sheet-2026',
        ])
        ->assertSessionHasErrors(['email' => 'An account with this email address already exists.']);

    expect(User::query()->count())->toBe(1);
});

it('rejects an account with a missing name, an unknown role or an unconfirmed password', function (array $payload, string $field): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->from(route('users.create'))
        ->post(route('users.store'), [
            'name' => 'Carla Dizon',
            'email' => 'carla.dizon@hrmd.test',
            'role' => UserRole::HrStaff->value,
            'password' => 'Tally-Sheet-2026',
            'password_confirmation' => 'Tally-Sheet-2026',
            ...$payload,
        ])
        ->assertSessionHasErrors($field);

    expect(User::query()->where('email', 'carla.dizon@hrmd.test')->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'no email' => [['email' => ''], 'email'],
    'capitals in the email' => [['email' => 'Carla.Dizon@HRMD.test'], 'email'],
    'unknown role' => [['role' => 'mayor'], 'role'],
    'no role' => [['role' => ''], 'role'],
    'short password' => [['password' => 'abc', 'password_confirmation' => 'abc'], 'password'],
    'mismatched password' => [['password_confirmation' => 'something-else'], 'password'],
]);

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('shows the edit form with the policy decisions already made', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff(['name' => 'Benito Reyes']);

    $this->actingAs($admin)
        ->get(route('users.edit', $staff))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('users/edit')
            ->where('user.name', 'Benito Reyes')
            ->where('user.role', UserRole::HrStaff->value)
            ->where('can.changeRole', true)
            ->where('can.deactivate', true)
            ->where('can.delete', true)
        );
});

it('tells the edit form which actions the policy refuses', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->get(route('users.edit', $admin))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.isSelf', true)
            ->where('can.changeRole', false)
            ->where('can.deactivate', false)
            ->where('can.delete', false)
        );
});

it('updates a name, an email address and a role', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff(['name' => 'Benito Reyes', 'email' => 'benito@hrmd.test']);

    $this->actingAs($admin)
        ->patch(route('users.update', $staff), [
            'name' => 'Benito R. Reyes',
            'email' => 'b.reyes@hrmd.test',
            'role' => UserRole::HrAdmin->value,
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $staff->refresh();

    expect($staff->name)->toBe('Benito R. Reyes')
        ->and($staff->email)->toBe('b.reyes@hrmd.test')
        ->and($staff->role())->toBe(UserRole::HrAdmin);
});

it('keeps the current password when both password fields are left empty', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff();
    $before = $staff->password;

    $this->actingAs($admin)
        ->patch(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => UserRole::HrStaff->value,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($staff->refresh()->password)->toBe($before);
});

it('resets a password when one is given', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff();

    $this->actingAs($admin)
        ->patch(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => UserRole::HrStaff->value,
            'password' => 'New-Password-2026',
            'password_confirmation' => 'New-Password-2026',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('New-Password-2026', $staff->refresh()->password))->toBeTrue();
});

it('lets an account keep its own email address on update', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff(['email' => 'benito@hrmd.test']);

    $this->actingAs($admin)
        ->patch(route('users.update', $staff), [
            'name' => 'Benito Reyes',
            'email' => 'benito@hrmd.test',
            'role' => UserRole::HrStaff->value,
        ])
        ->assertSessionHasNoErrors();
});

it('refuses an email address another account already uses', function (): void {
    $admin = hrAdmin(['email' => 'ana@hrmd.test']);
    $staff = hrStaff(['email' => 'benito@hrmd.test']);

    $this->actingAs($admin)
        ->from(route('users.edit', $staff))
        ->patch(route('users.update', $staff), [
            'name' => 'Benito Reyes',
            'email' => 'ana@hrmd.test',
            'role' => UserRole::HrStaff->value,
        ])
        ->assertSessionHasErrors(['email' => 'Another account already uses this email address.']);

    expect($staff->refresh()->email)->toBe('benito@hrmd.test');
});

it('refuses to let an admin demote themselves through the edit form', function (): void {
    $admin = hrAdmin();
    hrAdmin(); // so the refusal is about self-demotion, not the last admin

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::HrStaff->value,
        ])
        ->assertForbidden();

    expect($admin->refresh()->role())->toBe(UserRole::HrAdmin);
});

/*
 * The only-active-admin branch of changeRole/deactivate/delete cannot be
 * reached over HTTP: whoever is signed in is themselves an active admin, so the
 * person they are acting on is never the last one. It is kept as a second line
 * of defence for console commands and future bulk tools, and is covered
 * directly in UserPolicyTest.
 */
it('refuses to demote the only administrator left, who is always yourself', function (): void {
    $sole = hrAdmin(['name' => 'Only Admin']);

    $this->actingAs($sole)
        ->patch(route('users.update', $sole), [
            'name' => $sole->name,
            'email' => $sole->email,
            'role' => UserRole::HrStaff->value,
        ])
        ->assertForbidden();

    expect($sole->refresh()->role())->toBe(UserRole::HrAdmin);
});

it('lets an admin edit their own name without touching their role', function (): void {
    $admin = hrAdmin(['name' => 'Ana Admin']);

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), [
            'name' => 'Ana R. Admin',
            'email' => $admin->email,
            'role' => UserRole::HrAdmin->value,
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->name)->toBe('Ana R. Admin');
});

/*
|--------------------------------------------------------------------------
| Deactivating, reactivating and deleting
|--------------------------------------------------------------------------
*/

it('deactivates and reactivates an account', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff(['name' => 'Benito Reyes']);

    $this->actingAs($admin)
        ->from(route('users.index'))
        ->patch(route('users.deactivate', $staff))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('success', 'Benito Reyes can no longer sign in.');

    expect($staff->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->from(route('users.index'))
        ->patch(route('users.activate', $staff))
        ->assertSessionHas('success', 'Benito Reyes can sign in again.');

    expect($staff->refresh()->is_active)->toBeTrue();
});

it('refuses to deactivate your own account', function (): void {
    $admin = hrAdmin();
    hrAdmin();

    $this->actingAs($admin)
        ->patch(route('users.deactivate', $admin))
        ->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

it('deletes an account that never encoded anything', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff(['name' => 'Benito Reyes']);

    $this->actingAs($admin)
        ->delete(route('users.destroy', $staff))
        ->assertRedirect(route('users.index'))
        ->assertSessionHas('success', "Benito Reyes's account has been deleted.");

    expect(User::query()->whereKey($staff->id)->exists())->toBeFalse();
});

it('refuses to delete an account that encoded leave records', function (): void {
    $admin = hrAdmin();
    $encoder = hrStaff();

    LeaveRecord::factory()->create(['encoded_by' => $encoder->id]);

    $this->actingAs($admin)
        ->delete(route('users.destroy', $encoder))
        ->assertForbidden();

    expect(User::query()->whereKey($encoder->id)->exists())->toBeTrue();
});

it('refuses to delete your own account', function (): void {
    $admin = hrAdmin();
    hrAdmin();

    $this->actingAs($admin)
        ->delete(route('users.destroy', $admin))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});
