<?php

declare(strict_types=1);

use App\Models\Employee;

it('formats the alphabetical listing name', function (): void {
    $employee = Employee::factory()->create([
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Pascual',
        'suffix' => 'Jr.',
    ]);

    expect($employee->list_name)->toBe('Dela Cruz, Juan P. Jr.');
});

it('formats the natural reading name', function (): void {
    $employee = Employee::factory()->create([
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Pascual',
        'suffix' => 'Jr.',
    ]);

    expect($employee->full_name)->toBe('Juan P. Dela Cruz Jr.');
});

it('omits the middle initial and suffix when absent', function (): void {
    $employee = Employee::factory()->create([
        'last_name' => 'Santos',
        'first_name' => 'Maria',
        'middle_name' => null,
        'suffix' => null,
    ]);

    expect($employee->middle_initial)->toBeNull()
        ->and($employee->list_name)->toBe('Santos, Maria')
        ->and($employee->full_name)->toBe('Maria Santos');
});

it('sorts alphabetically regardless of case', function (): void {
    // PostgreSQL compares text case-sensitively, so a plain ORDER BY last_name
    // would sort every lowercase name after "Zamora".
    foreach (['Zamora', 'abad', 'Dela Cruz'] as $lastName) {
        Employee::factory()->create(['last_name' => $lastName]);
    }

    expect(Employee::query()->orderedByName()->pluck('last_name')->all())
        ->toBe(['abad', 'Dela Cruz', 'Zamora']);
});

it('matches letter tabs regardless of case', function (): void {
    Employee::factory()->create(['last_name' => 'dela Cruz']);
    Employee::factory()->create(['last_name' => 'Santos']);

    expect(Employee::query()->startingWith('D')->pluck('last_name')->all())
        ->toBe(['dela Cruz'])
        ->and(Employee::query()->startingWith('d')->count())->toBe(1);
});

it('searches name, position and employee number case-insensitively', function (): void {
    $employee = Employee::factory()->create([
        'employee_no' => '2024-00123',
        'last_name' => 'Villanueva',
        'first_name' => 'Rowena',
        'position_title' => 'Bookbinder II',
    ]);

    foreach (['villanueva', 'ROWENA', 'bookbinder', '00123'] as $term) {
        expect(Employee::query()->search($term)->pluck('id')->all())
            ->toBe([$employee->id], "searching for '{$term}'");
    }
});

it('excludes inactive employees from the active scope', function (): void {
    Employee::factory()->count(2)->create();
    Employee::factory()->inactive()->create();

    expect(Employee::query()->active()->count())->toBe(2)
        ->and(Employee::query()->count())->toBe(3);
});

it('soft deletes rather than removing the row', function (): void {
    $employee = Employee::factory()->create();

    $employee->delete();

    expect(Employee::query()->count())->toBe(0)
        ->and(Employee::withTrashed()->count())->toBe(1);
});

it('belongs to an office', function (): void {
    $employee = Employee::factory()->create();

    expect($employee->office)->not->toBeNull()
        ->and($employee->office->employees)->toHaveCount(1);
});
