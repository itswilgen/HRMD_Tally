<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LeaveRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * UserPolicy guards two separate things: that only an HR Admin touches
 * accounts, and that an HR Admin cannot lock the office out of its own system
 * — by demoting or deactivating the only administrator left, including
 * themselves. A denial carries the sentence the screen shows the person.
 */
it('lets an HR Admin manage accounts', function (): void {
    $admin = hrAdmin();
    $other = hrStaff();

    expect($admin->can('viewAny', User::class))->toBeTrue()
        ->and($admin->can('create', User::class))->toBeTrue()
        ->and($admin->can('update', $other))->toBeTrue()
        ->and($admin->can('view', $other))->toBeTrue();
});

it('keeps HR Staff out of account management entirely', function (): void {
    $staff = hrStaff();
    $other = hrAdmin();

    expect($staff->can('viewAny', User::class))->toBeFalse()
        ->and($staff->can('create', User::class))->toBeFalse()
        ->and($staff->can('update', $other))->toBeFalse()
        ->and($staff->can('changeRole', $other))->toBeFalse()
        ->and($staff->can('deactivate', $other))->toBeFalse()
        ->and($staff->can('delete', $other))->toBeFalse();
});

it('refuses to let an admin change their own role', function (): void {
    $admin = hrAdmin();
    hrAdmin(); // a second admin, so the refusal is about self-demotion only

    $response = Gate::forUser($admin)->inspect('changeRole', $admin);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe('You cannot change your own role. Ask another HR Admin to do it.');
});

it('refuses to let an admin deactivate their own account', function (): void {
    $admin = hrAdmin();
    hrAdmin();

    $response = Gate::forUser($admin)->inspect('deactivate', $admin);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe('You cannot deactivate your own account.');
});

it('refuses to let an admin delete their own account', function (): void {
    $admin = hrAdmin();
    hrAdmin();

    $response = Gate::forUser($admin)->inspect('delete', $admin);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe('You cannot delete your own account.');
});

it('protects the only active HR Admin from being demoted, deactivated or deleted', function (string $ability): void {
    $admin = hrAdmin(['name' => 'Remaining Admin']);
    $lastOne = hrAdmin(['name' => 'Only Admin']);

    // Leaves exactly one active administrator: $lastOne.
    $admin->update(['is_active' => false]);

    $response = Gate::forUser($admin)->inspect($ability, $lastOne);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toContain('Only Admin is the only active HR Admin');
})->with(['changeRole', 'deactivate', 'delete']);

it('allows an admin to be demoted once another active admin exists', function (): void {
    $actor = hrAdmin();
    $target = hrAdmin();

    expect($actor->can('changeRole', $target))->toBeTrue()
        ->and($actor->can('deactivate', $target))->toBeTrue();
});

it('does not treat a deactivated admin as the last one standing', function (): void {
    $actor = hrAdmin();
    $deactivated = hrAdmin();
    $deactivated->update(['is_active' => false]);

    // $actor is the only active admin now, but the target is not, so none of
    // these rules should apply to it.
    expect($actor->can('changeRole', $deactivated))->toBeTrue()
        ->and($actor->can('delete', $deactivated))->toBeTrue();
});

it('does not protect an HR Staff account as if it were the last admin', function (): void {
    $admin = hrAdmin();
    $staff = hrStaff();

    expect($admin->can('deactivate', $staff))->toBeTrue()
        ->and($admin->can('delete', $staff))->toBeTrue();
});

it('refuses to delete an account that has encoded leave records', function (): void {
    $admin = hrAdmin();
    $encoder = hrStaff(['name' => 'Encoder Staff']);

    LeaveRecord::factory()->create(['encoded_by' => $encoder->id]);

    $response = Gate::forUser($admin)->inspect('delete', $encoder);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toContain('Deactivate the account instead');
});

it('still refuses to delete an encoder whose records were soft deleted', function (): void {
    $admin = hrAdmin();
    $encoder = hrStaff();

    $record = LeaveRecord::factory()->create(['encoded_by' => $encoder->id]);
    $record->delete();

    expect($admin->can('delete', $encoder))->toBeFalse();
});

it('allows deleting an account that never encoded anything', function (): void {
    $admin = hrAdmin();
    $neverUsed = hrStaff();

    expect($admin->can('delete', $neverUsed))->toBeTrue();
});

it('refuses every account ability to a user with no role at all', function (): void {
    seedRolesAndPermissions();

    $roleless = User::factory()->create();
    $target = hrAdmin();

    expect($roleless->role())->toBeNull()
        ->and($roleless->can('viewAny', User::class))->toBeFalse()
        ->and($roleless->can('update', $target))->toBeFalse();
});

it('reads a single role back as an enum', function (): void {
    expect(hrAdmin()->role())->toBe(UserRole::HrAdmin)
        ->and(hrStaff()->role())->toBe(UserRole::HrStaff);
});
