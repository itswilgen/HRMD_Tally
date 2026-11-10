<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * First-time setup of a real installation.
 *
 * The point of the command is what it leaves out: no development logins, no
 * fictional employees, no password anyone else knows. And a second run must
 * not undo what HR changed after the first.
 */
const INSTALL_PASSWORD = 'Tally-Sheet-2026!';

it('seeds the reference data and creates the first HR Admin', function (): void {
    $this->artisan('hrmd:install')
        ->expectsQuestion('Full name', 'Maria Santos')
        ->expectsQuestion('Email address', 'maria.santos@example.gov.ph')
        ->expectsQuestion('Password', INSTALL_PASSWORD)
        ->expectsQuestion('Type the password again', INSTALL_PASSWORD)
        ->assertSuccessful();

    $admin = User::where('email', 'maria.santos@example.gov.ph')->sole();

    expect($admin->hasRole(UserRole::HrAdmin->value))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check(INSTALL_PASSWORD, $admin->password))->toBeTrue()
        ->and(Office::count())->toBeGreaterThan(0)
        ->and(LeaveType::count())->toBeGreaterThan(0)
        ->and(Holiday::count())->toBeGreaterThan(0);
});

it('never creates the development logins or sample employees', function (): void {
    $this->artisan('hrmd:install')
        ->expectsQuestion('Full name', 'Maria Santos')
        ->expectsQuestion('Email address', 'maria.santos@example.gov.ph')
        ->expectsQuestion('Password', INSTALL_PASSWORD)
        ->expectsQuestion('Type the password again', INSTALL_PASSWORD)
        ->assertSuccessful();

    expect(User::whereIn('email', ['admin@hrmd.test', 'staff@hrmd.test'])->exists())->toBeFalse()
        ->and(User::count())->toBe(1)
        ->and(Employee::count())->toBe(0);
});

it('keeps reference data HR already changed', function (): void {
    Office::factory()->create(['name' => 'City Human Resource Office']);

    $this->artisan('db:seed', ['--class' => RoleSeeder::class]);
    User::factory()->create()->assignRole(UserRole::HrAdmin->value);

    $this->artisan('hrmd:install')->assertSuccessful();

    // The office seeder would have added a dozen offices to an empty table.
    expect(Office::pluck('name')->all())->toBe(['City Human Resource Office']);
});

it('skips the account once an HR Admin exists', function (): void {
    $this->artisan('db:seed', ['--class' => RoleSeeder::class]);
    User::factory()->create()->assignRole(UserRole::HrAdmin->value);

    $this->artisan('hrmd:install')
        ->expectsOutputToContain('An HR Admin already exists')
        ->assertSuccessful();

    expect(User::count())->toBe(1);
});

it('creates nothing when the two passwords differ', function (): void {
    $this->artisan('hrmd:install')
        ->expectsQuestion('Full name', 'Maria Santos')
        ->expectsQuestion('Email address', 'maria.santos@example.gov.ph')
        ->expectsQuestion('Password', INSTALL_PASSWORD)
        ->expectsQuestion('Type the password again', 'something-else')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
