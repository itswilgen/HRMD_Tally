<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed();
});

it('seeds the two roles', function (): void {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['hr_admin', 'hr_staff']);
});

it('seeds an admin and a staff account with their roles', function (): void {
    $admin = User::query()->where('email', 'admin@hrmd.test')->sole();
    $staff = User::query()->where('email', 'staff@hrmd.test')->sole();

    expect($admin->isHrAdmin())->toBeTrue()
        ->and($admin->isHrStaff())->toBeFalse()
        ->and($staff->isHrStaff())->toBeTrue()
        ->and($staff->isHrAdmin())->toBeFalse();
});

it('seeds the provisional leave types with distinct codes and colours', function (): void {
    $types = LeaveType::query()->ordered()->get();

    expect($types)->toHaveCount(6)
        ->and($types->pluck('code')->all())->toBe(['VL', 'SL', 'MFL', 'SPL', 'CTO', 'WL'])
        ->and($types->pluck('color')->unique())->toHaveCount(6)
        ->and($types->every(fn (LeaveType $type): bool => $type->is_active))->toBeTrue();
});

it('seeds offices, holidays and employees', function (): void {
    expect(Office::query()->count())->toBeGreaterThan(0)
        ->and(Holiday::query()->count())->toBeGreaterThan(0)
        ->and(Employee::query()->count())->toBeGreaterThan(0);
});

it('seeds some inactive employees so the hidden-from-encoding rule has coverage', function (): void {
    expect(Employee::query()->where('is_active', false)->count())->toBeGreaterThan(0);
});

it('gives every employee a Filipino-style name, not a Faker en_US fallback', function (): void {
    // Faker's en_PH locale has no Person provider, so names must come from the
    // factory's own pools. "Ankunding" and friends mean that broke again.
    $surnames = Employee::query()->pluck('last_name');

    expect($surnames)->not->toBeEmpty()
        ->and($surnames->intersect(['Ankunding', 'Kassulke', 'Bergnaum', 'Emard', 'Denesik']))
        ->toBeEmpty();
});

it('seeds leave records that respect the no-double-encoding rule', function (): void {
    expect(LeaveRecord::query()->count())->toBeGreaterThan(0);

    $duplicates = DB::table('leave_records')
        ->selectRaw('employee_id, leave_date, day_part, count(*) as total')
        ->whereNull('deleted_at')
        ->groupBy('employee_id', 'leave_date', 'day_part')
        ->havingRaw('count(*) > 1')
        ->count();

    expect($duplicates)->toBe(0);
});

it('never seeds leave on a weekend or a holiday', function (): void {
    $holidays = Holiday::query()->pluck('date')->map->toDateString()->all();

    $offending = LeaveRecord::query()->get()->filter(
        fn (LeaveRecord $record): bool => $record->leave_date->isWeekend()
            || in_array($record->leave_date->toDateString(), $holidays, true),
    );

    expect($offending)->toBeEmpty();
});

it('attributes every seeded leave record to an encoder', function (): void {
    expect(LeaveRecord::query()->whereNull('encoded_by')->count())->toBe(0);
});

it('keeps the first admin role assignment idempotent when reseeded', function (): void {
    $this->seed();

    expect(User::query()->where('email', 'admin@hrmd.test')->count())->toBe(1)
        ->and(Role::query()->where('name', UserRole::HrAdmin->value)->count())->toBe(1);
});
