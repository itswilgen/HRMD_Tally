<?php

declare(strict_types=1);

use App\Enums\LeaveChipColor;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Services\LeaveEncodingService;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Settings → Leave types (business rule 8). The fifteen seeded codes are in
 * the test database from the start, so every test works on top of them.
 */
function leaveType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

/**
 * @return list<string>
 */
function orderedCodes(): array
{
    return LeaveType::query()->ordered()->pluck('code')->all();
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('leave-types.index'))->assertRedirect(route('login'));
});

it('lists every leave type in order, with what uses it', function (): void {
    $sick = leaveType('SL');
    $wellness = leaveType('WL');
    $wellness->update(['is_active' => false]);
    LeaveRecord::factory()->count(2)->for($sick, 'leaveType')->sequence(['leave_date' => '2026-03-02'], ['leave_date' => '2026-03-03'])->create();
    LeaveCredit::factory()->for(leaveType('VL'), 'leaveType')->create();

    $this->actingAs(hrAdmin())
        ->get(route('leave-types.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/leave-types')
            ->has('leaveTypes', 15)
            ->where('leaveTypes.0.code', 'VL')
            ->where('leaveTypes.0.leaveCreditsCount', 1)
            ->where('leaveTypes.0.inUse', true)
            ->where('leaveTypes.2.code', 'SL')
            ->where('leaveTypes.2.leaveRecordsCount', 2)
            ->where('leaveTypes.2.inUse', true)
            ->where('leaveTypes.1.code', 'MFL')
            ->where('leaveTypes.1.inUse', false)
            ->where('leaveTypes.14.code', 'WL')
            ->where('leaveTypes.14.isActive', false)
            ->has('colors', count(LeaveChipColor::cases()))
            ->where('can.manage', true)
        );
});

it('counts a deleted leave record as use, like the delete rule does', function (): void {
    $record = LeaveRecord::factory()->for(leaveType('MFL'), 'leaveType')->create();
    $record->delete();

    $this->actingAs(hrAdmin())
        ->get(route('leave-types.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leaveTypes.1.code', 'MFL')
            ->where('leaveTypes.1.leaveRecordsCount', 1)
            ->where('leaveTypes.1.inUse', true)
        );
});

it('shows HR Staff the list without the controls to change it', function (): void {
    $this->actingAs(hrStaff())
        ->get(route('leave-types.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('leaveTypes', 15)
            ->where('can.manage', false)
        );
});

it('refuses every leave type change to HR Staff', function (string $method, string $route): void {
    $type = leaveType('MFL');
    $url = in_array($route, ['leave-types.store', 'leave-types.reorder'], true) ? route($route) : route($route, $type);

    $this->actingAs(hrStaff())
        ->{$method}($url, ['code' => 'NEW', 'name' => 'New Leave', 'color' => 'sky', 'ids' => LeaveType::query()->pluck('id')->all()])
        ->assertForbidden();

    expect(LeaveType::query()->count())->toBe(15)
        ->and($type->fresh()?->is_active)->toBeTrue()
        ->and(Activity::query()->where('log_name', 'leave_type')->exists())->toBeFalse();
})->with([
    'add' => ['post', 'leave-types.store'],
    'edit' => ['patch', 'leave-types.update'],
    'reorder' => ['put', 'leave-types.reorder'],
    'retire' => ['patch', 'leave-types.retire'],
    'restore' => ['patch', 'leave-types.restore'],
    'delete' => ['delete', 'leave-types.destroy'],
]);

it('adds a leave type at the end of the list, in capitals, and logs it', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->post(route('leave-types.store'), ['code' => ' sbl ', 'name' => '  Special   Birthday Leave ', 'color' => 'rose'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $type = leaveType('SBL');

    expect($type->name)->toBe('Special Birthday Leave')
        ->and($type->color)->toBe('rose')
        ->and($type->is_active)->toBeTrue()
        ->and(orderedCodes())->toHaveCount(16)
        ->and(orderedCodes()[15])->toBe('SBL');

    $log = Activity::query()->where('log_name', 'leave_type')->sole();

    expect($log->event)->toBe('created')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->description)->toBe('Added leave type SBL (Special Birthday Leave)');
});

it('offers a new type to encoding straight away', function (): void {
    $this->actingAs(hrAdmin())
        ->post(route('leave-types.store'), ['code' => 'SBL', 'name' => 'Special Birthday Leave', 'color' => 'rose']);

    expect(array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code'))->toContain('SBL');
});

it('validates a new leave type', function (array $data, string $field): void {
    $this->actingAs(hrAdmin())
        ->post(route('leave-types.store'), [...['code' => 'NEW', 'name' => 'New Leave', 'color' => 'sky'], ...$data])
        ->assertSessionHasErrors($field);

    expect(LeaveType::query()->count())->toBe(15);
})->with([
    'no code' => [['code' => ''], 'code'],
    'a space in the code' => [['code' => 'S L'], 'code'],
    'a dash in the code' => [['code' => 'SL-2'], 'code'],
    'a code over 10 characters' => [['code' => 'ABCDEFGHIJK'], 'code'],
    'a code already used' => [['code' => 'sl'], 'code'],
    'no name' => [['name' => '  '], 'name'],
    'a name already used' => [['name' => 'Sick Leave'], 'name'],
    'a name over 100 characters' => [['name' => str_repeat('a', 101)], 'name'],
    'no colour' => [['color' => ''], 'color'],
    'a colour not in the palette' => [['color' => '#ff0000'], 'color'],
]);

it('edits the name and colour of a type that is in use', function (): void {
    $sick = leaveType('SL');
    LeaveRecord::factory()->for($sick, 'leaveType')->create();

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.update', $sick), ['code' => 'SL', 'name' => 'Sick Leave (with pay)', 'color' => 'rose'])
        ->assertSessionHasNoErrors();

    expect($sick->fresh()?->name)->toBe('Sick Leave (with pay)')
        ->and($sick->fresh()?->color)->toBe('rose');

    $log = Activity::query()->where('log_name', 'leave_type')->sole();

    expect($log->event)->toBe('updated')
        ->and($log->properties['old'])->toBe(['name' => 'Sick Leave', 'color' => 'amber'])
        ->and($log->properties['attributes'])->toBe(['name' => 'Sick Leave (with pay)', 'color' => 'rose']);
});

it('locks the code of a type that is in use', function (string $use): void {
    $sick = leaveType('SL');

    if ($use === 'record') {
        LeaveRecord::factory()->for($sick, 'leaveType')->create()->delete();
    } else {
        LeaveCredit::factory()->for($sick, 'leaveType')->create();
    }

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.update', $sick), ['code' => 'SCK', 'name' => 'Sick Leave', 'color' => 'amber'])
        ->assertSessionHasErrors(['code' => 'SL is already on leave records or credit figures, so its code cannot change. Add a new type instead.']);

    expect($sick->fresh()?->code)->toBe('SL');
})->with(['a deleted leave record' => 'record', 'a credit figure' => 'credit']);

it('changes the code of a type nobody has used yet', function (): void {
    $type = leaveType('MFL');

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.update', $type), ['code' => 'FL', 'name' => 'Forced Leave', 'color' => 'violet'])
        ->assertSessionHasNoErrors();

    expect($type->fresh()?->code)->toBe('FL');
});

it('logs nothing when an edit changes nothing', function (): void {
    $type = leaveType('SL');

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.update', $type), ['code' => 'sl', 'name' => 'Sick Leave', 'color' => 'amber'])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'leave_type')->exists())->toBeFalse();
});

it('lets a type keep its own code and name on edit', function (): void {
    $type = leaveType('SL');

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.update', $type), ['code' => 'SL', 'name' => 'Sick Leave', 'color' => 'teal'])
        ->assertSessionHasNoErrors();

    expect($type->fresh()?->color)->toBe('teal');
});

it('saves a new order, which the encoding picker follows', function (): void {
    $order = LeaveType::query()->ordered()->pluck('id')->all();
    [$first, $second] = [$order[0], $order[1]];
    $order[0] = $second;
    $order[1] = $first;

    $this->actingAs(hrAdmin())
        ->put(route('leave-types.reorder'), ['ids' => $order])
        ->assertSessionHasNoErrors();

    expect(array_slice(orderedCodes(), 0, 3))->toBe(['MFL', 'VL', 'SL'])
        ->and(array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code')[0])->toBe('MFL');

    $log = Activity::query()->where('log_name', 'leave_type')->sole();

    expect($log->event)->toBe('reordered')
        ->and($log->properties['old'][0])->toBe('VL')
        ->and($log->properties['attributes'][0])->toBe('MFL');
});

it('logs nothing when the order is saved unchanged', function (): void {
    $this->actingAs(hrAdmin())
        ->put(route('leave-types.reorder'), ['ids' => LeaveType::query()->ordered()->pluck('id')->all()])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'leave_type')->exists())->toBeFalse();
});

it('refuses an order that does not name every type exactly once', function (Closure $ids): void {
    $before = orderedCodes();

    $this->actingAs(hrAdmin())
        ->put(route('leave-types.reorder'), ['ids' => $ids()])
        ->assertSessionHasErrors('ids');

    expect(orderedCodes())->toBe($before);
})->with([
    'one missing' => fn () => array_slice(LeaveType::query()->ordered()->pluck('id')->all(), 1),
    'one twice' => function () {
        $ids = LeaveType::query()->ordered()->pluck('id')->all();
        $ids[1] = $ids[0];

        return $ids;
    },
    'an id that does not exist' => fn () => [...array_slice(LeaveType::query()->ordered()->pluck('id')->all(), 1), 999999],
    'nothing' => fn () => [],
]);

it('retires a type: its records stay, encoding no longer offers it', function (): void {
    $sick = leaveType('SL');
    $record = LeaveRecord::factory()->for($sick, 'leaveType')->create();

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.retire', $sick))
        ->assertSessionHasNoErrors();

    expect($sick->fresh()?->is_active)->toBeFalse()
        ->and(LeaveRecord::query()->find($record->id))->not->toBeNull()
        ->and(array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code'))->not->toContain('SL')
        ->and(Activity::query()->where('log_name', 'leave_type')->sole()->event)->toBe('retired');
});

it('restores a retired type to the encoding list', function (): void {
    $sick = leaveType('SL');
    $sick->update(['is_active' => false]);

    $this->actingAs(hrAdmin())
        ->patch(route('leave-types.restore', $sick))
        ->assertSessionHasNoErrors();

    expect($sick->fresh()?->is_active)->toBeTrue()
        ->and(array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code'))->toContain('SL')
        ->and(Activity::query()->where('log_name', 'leave_type')->sole()->event)->toBe('restored');
});

it('deletes a type that was never used, and logs what it was', function (): void {
    $type = LeaveType::factory()->create(['code' => 'OOPS', 'name' => 'Typed by mistake']);

    $this->actingAs(hrAdmin())
        ->delete(route('leave-types.destroy', $type))
        ->assertSessionHasNoErrors();

    expect(LeaveType::query()->find($type->id))->toBeNull();

    $log = Activity::query()->where('log_name', 'leave_type')->sole();

    expect($log->event)->toBe('deleted')
        ->and($log->description)->toBe('Deleted leave type OOPS (Typed by mistake)');
});

it('refuses to delete a type that is in use, and says why', function (string $use): void {
    $sick = leaveType('SL');
    $employee = Employee::factory()->create();

    if ($use === 'record') {
        LeaveRecord::factory()->for($employee)->for($sick, 'leaveType')->create();
    } else {
        // Credits alone used to slip past the check and hit the foreign key.
        LeaveCredit::factory()->for($employee)->for($sick, 'leaveType')->create();
    }

    $this->actingAs(hrAdmin())
        ->delete(route('leave-types.destroy', $sick))
        ->assertForbidden();

    expect(LeaveType::query()->find($sick->id))->not->toBeNull();
})->with(['a leave record' => 'record', 'a credit figure' => 'credit']);

it('keeps the PHP palette keys in step with the chip tokens', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $chip = (string) file_get_contents(resource_path('js/components/leave-chip.tsx'));

    foreach (LeaveChipColor::cases() as $color) {
        expect($css)->toContain("--chip-{$color->value}:")
            ->and($chip)->toContain("{$color->value}: 'bg-chip-{$color->value} text-chip-{$color->value}-ink'");
    }
});
