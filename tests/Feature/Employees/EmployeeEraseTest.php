<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRecord;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restoring a deleted employee record, and erasing one for good.
 *
 * Erasing is the single exception to "nothing is ever erased" and the only
 * irreversible action in the system, so most of what is tested here is what it
 * refuses: a record still on the masterlist, a record with leave on file, an
 * HR Admin without the permission, HR Staff, a guest.
 */
function deletedEmployee(array $attributes = []): Employee
{
    $employee = Employee::factory()->create($attributes);
    $employee->delete();

    return $employee->fresh() ?? $employee;
}

it('lists the deleted records behind the status filter', function (): void {
    $admin = hrAdmin();
    $deleted = deletedEmployee(['last_name' => 'Duplicado', 'first_name' => 'Juan']);
    Employee::factory()->create(['last_name' => 'Zamora']);

    $this->actingAs($admin)
        ->get(route('employees.index', ['status' => 'deleted']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('employees/index')
                // Only the deleted row: the live masterlist is the other view.
                ->count('employees.data', 1)
                ->where('employees.data.0.id', $deleted->id)
                ->where('employees.data.0.deletedAt', $deleted->deleted_at?->toIso8601String())
                ->where('filters.status', 'deleted')
        );
});

it('leaves deletedAt null for employees still on the masterlist', function (): void {
    $admin = hrAdmin();
    Employee::factory()->create();

    $this->actingAs($admin)
        ->get(route('employees.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('employees.data.0.deletedAt', null));
});

it('keeps the deleted records away from HR Staff', function (): void {
    $staff = hrStaff();
    deletedEmployee();

    $this->actingAs($staff)
        ->get(route('employees.index', ['status' => 'deleted']))
        ->assertForbidden();
});

it('restores a deleted record exactly as it was', function (): void {
    $admin = hrAdmin();
    // Deactivated before being deleted: restoring must not quietly put someone
    // back on the encoding list they had already been taken off.
    $employee = deletedEmployee([
        'last_name' => 'Reyes',
        'first_name' => 'Ana',
        'middle_name' => null,
        'suffix' => null,
        'is_active' => false,
    ]);

    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->patch(route('employees.restore', $employee->id))
        ->assertRedirect(route('employees.index', ['status' => 'deleted']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Ana Reyes is back on the masterlist, still deactivated.');

    $restored = Employee::query()->find($employee->id);

    expect($restored)->not->toBeNull()
        ->and($restored?->deleted_at)->toBeNull()
        ->and($restored?->is_active)->toBeFalse();
});

it('refuses to let HR Staff restore a deleted record', function (): void {
    $staff = hrStaff();
    $employee = deletedEmployee();

    $this->actingAs($staff)
        ->patch(route('employees.restore', $employee->id))
        ->assertForbidden();

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeFalse();
});

it('erases the ticked deleted records for good', function (): void {
    $admin = hrAdmin();
    $abad = deletedEmployee(['last_name' => 'Abad', 'first_name' => 'Maria']);
    $santos = deletedEmployee(['last_name' => 'Santos', 'first_name' => 'Pedro']);
    $spared = deletedEmployee(['last_name' => 'Zamora']);

    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->delete(route('employees.erase'), ['ids' => [$abad->id, $santos->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '2 records were erased permanently. Only the activity log entry is left.');

    // Gone from the table itself, not merely from the lists: withTrashed is the
    // assertion that matters here, because that is what a soft delete passes.
    expect(Employee::withTrashed()->whereKey([$abad->id, $santos->id])->count())->toBe(0)
        ->and(Employee::withTrashed()->whereKey($spared->id)->exists())->toBeTrue();
});

it('writes the erased names and numbers to the activity log', function (): void {
    $admin = hrAdmin();
    $employee = deletedEmployee([
        'last_name' => 'Duplicado',
        'first_name' => 'Juan',
        'middle_name' => null,
        'suffix' => null,
        'employee_no' => '2026-00123',
    ]);

    $this->actingAs($admin)->delete(route('employees.erase'), ['ids' => [$employee->id]]);

    $activity = Activity::query()->where('event', 'force_deleted')->sole();

    // The log entry is the only thing left of the record, so it has to carry
    // enough to recognise who it was — not just a count.
    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->description)->toBe('Permanently erased 1 employee record')
        ->and($activity->properties['count'])->toBe(1)
        ->and($activity->properties['employees'])->toBe(['Duplicado, Juan (2026-00123)']);
});

it('refuses to erase a record that is still on the masterlist', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create();

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.erase'), ['ids' => [$employee->id]])
        ->assertSessionHasErrors('ids.0');

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
});

it('keeps a deleted record that has leave on file and erases the rest', function (): void {
    $admin = hrAdmin();
    $mistake = deletedEmployee(['last_name' => 'Duplicado', 'first_name' => 'Juan']);
    $encoded = deletedEmployee([
        'last_name' => 'Reyes',
        'first_name' => 'Ana',
        'middle_name' => null,
        'suffix' => null,
    ]);
    LeaveRecord::factory()->count(2)->create(['employee_id' => $encoded->id]);

    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->delete(route('employees.erase'), ['ids' => [$mistake->id, $encoded->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '1 record was erased permanently. Only the activity log entry is left.')
        ->assertSessionHas(
            'error',
            '1 of the ticked records has leave on file and was kept: Reyes, Ana. A record with leave days behind it cannot be erased, because the tally sheets it is printed on must keep reading correctly.',
        );

    expect(Employee::withTrashed()->whereKey($mistake->id)->exists())->toBeFalse()
        ->and(Employee::withTrashed()->whereKey($encoded->id)->exists())->toBeTrue()
        ->and($encoded->leaveRecords()->count())->toBe(2);
});

it('erases nothing when every ticked record has leave on file', function (): void {
    $admin = hrAdmin();
    $employee = deletedEmployee(['last_name' => 'Macaraeg', 'first_name' => 'Rowena']);
    LeaveRecord::factory()->create(['employee_id' => $employee->id]);

    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->delete(route('employees.erase'), ['ids' => [$employee->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success');

    expect(Employee::withTrashed()->whereKey($employee->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'force_deleted')->exists())->toBeFalse();
});

it('keeps a deleted record whose leave records were themselves deleted', function (): void {
    $admin = hrAdmin();
    $employee = deletedEmployee([
        'last_name' => 'Reyes',
        'first_name' => 'Ana',
        'middle_name' => null,
        'suffix' => null,
    ]);
    $leave = LeaveRecord::factory()->create(['employee_id' => $employee->id]);
    $leave->delete();

    // A deleted leave record is still a recoverable row pointing at this
    // employee, and employee_id is restrictOnDelete, so erasing would destroy
    // a restorable history and then fail on the foreign key anyway.
    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->delete(route('employees.erase'), ['ids' => [$employee->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Reyes, Ana'));

    expect(Employee::withTrashed()->whereKey($employee->id)->exists())->toBeTrue();
});

it('refuses an HR Admin whose role has had the erase permission withheld', function (): void {
    $admin = hrAdmin();

    // The point of giving erasing its own permission: HR can take this one
    // away without touching the rest of what an HR Admin does. Deleting still
    // works in the tests above, which is the other half of that claim.
    Role::findByName(UserRole::HrAdmin->value)->revokePermissionTo(Permission::EraseEmployees->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $employee = deletedEmployee();

    $this->actingAs($admin)
        ->delete(route('employees.erase'), ['ids' => [$employee->id]])
        ->assertForbidden();

    expect(Employee::withTrashed()->whereKey($employee->id)->exists())->toBeTrue();
});

it('refuses to let HR Staff erase a record', function (): void {
    $staff = hrStaff();
    $employee = deletedEmployee();

    $this->actingAs($staff)
        ->delete(route('employees.erase'), ['ids' => [$employee->id]])
        ->assertForbidden();

    expect(Employee::withTrashed()->whereKey($employee->id)->exists())->toBeTrue();
});

it('sends guests asking to erase to the sign-in page', function (): void {
    $employee = deletedEmployee();

    $this->delete(route('employees.erase'), ['ids' => [$employee->id]])->assertRedirect(route('login'));

    expect(Employee::withTrashed()->whereKey($employee->id)->exists())->toBeTrue();
});

it('erases at most fifty records in one request', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->from(route('employees.index', ['status' => 'deleted']))
        ->delete(route('employees.erase'), ['ids' => range(1, 51)])
        ->assertSessionHasErrors(['ids' => 'Erase at most 50 records at a time.']);
});

it('frees the employee number of an erased record for reissue', function (): void {
    $admin = hrAdmin();
    // Safe only because an erased record has no leave history to misattach:
    // the leave check above is what makes reissuing the number harmless.
    $employee = deletedEmployee(['employee_no' => '2026-00123']);

    $this->actingAs($admin)->delete(route('employees.erase'), ['ids' => [$employee->id]]);

    expect(Employee::withTrashed()->where('employee_no', '2026-00123')->exists())->toBeFalse();
});
