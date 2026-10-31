<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\UserRole;
use Inertia\Testing\AssertableInertia;

/**
 * The permission matrix from section 3 of docs/PROJECT_CONTEXT.md.
 *
 * RoleSeeder builds the roles from UserRole::permissions(), so these tests are
 * what notices if the matrix in code quietly stops matching the agreed one —
 * particularly the salary rate column, which HR confirmed is HR Admin only.
 */
it('grants HR Admin every permission', function (): void {
    $admin = hrAdmin();

    foreach (Permission::cases() as $permission) {
        expect($admin->can($permission->value))
            ->toBeTrue("HR Admin should be able to {$permission->value}");
    }
});

it('grants HR Staff only encoding, employees, tally and exports', function (): void {
    $staff = hrStaff();

    expect($staff->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe([
            Permission::ManageEmployees->value,
            Permission::GenerateExports->value,
            Permission::ManageLeave->value,
            Permission::ViewTally->value,
        ]);
});

it('keeps the salary rate column away from HR Staff', function (): void {
    expect(hrStaff()->can(Permission::ViewSalary->value))->toBeFalse()
        ->and(hrAdmin()->can(Permission::ViewSalary->value))->toBeTrue();
});

it('keeps settings, accounts, the activity log and employee deactivation to HR Admin', function (): void {
    $staff = hrStaff();

    foreach ([
        Permission::ManageSettings,
        Permission::ManageUsers,
        Permission::ViewActivityLog,
        Permission::DeactivateEmployees,
    ] as $permission) {
        expect($staff->can($permission->value))
            ->toBeFalse("HR Staff should not be able to {$permission->value}");
    }
});

it('gives every permission a frontend key of its own', function (): void {
    $keys = array_map(
        static fn (Permission $permission): string => $permission->frontendKey(),
        Permission::cases(),
    );

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('shares what the signed-in user may do with every Inertia page', function (): void {
    $staff = hrStaff();

    $this->actingAs($staff)
        ->get('/dashboard')
        ->assertInertia(function (AssertableInertia $page) {
            $page->where('auth.user.role', UserRole::HrStaff->value)
                ->where('auth.user.roleLabel', 'HR Staff')
                ->where('auth.user.can.viewTally', true)
                ->where('auth.user.can.manageLeave', true)
                ->where('auth.user.can.manageUsers', false)
                ->where('auth.user.can.viewSalary', false)
                ->where('auth.user.can.manageSettings', false);
        });
});

it('never sends the password hash to the browser', function (): void {
    $this->actingAs(hrAdmin())
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('auth.user.password'));
});
