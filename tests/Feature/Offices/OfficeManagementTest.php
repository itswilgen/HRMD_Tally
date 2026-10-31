<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use Inertia\Testing\AssertableInertia;

it('sends guests to the sign-in page', function (): void {
    $this->get(route('offices.index'))->assertRedirect(route('login'));
});

it('lists the offices with the number of people in each', function (): void {
    $admin = hrAdmin();
    $treasurer = Office::factory()->create(['name' => 'City Treasurer']);
    Office::factory()->create(['name' => 'City Engineering']);

    Employee::factory()->count(2)->inOffice($treasurer)->create();

    $this->actingAs($admin)
        ->get(route('offices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/offices')
            ->has('offices', 2)
            // Alphabetical, so HR can find one in a list of forty.
            ->where('offices.0.name', 'City Engineering')
            ->where('offices.0.employeesCount', 0)
            ->where('offices.1.name', 'City Treasurer')
            ->where('offices.1.employeesCount', 2)
            ->where('can.manage', true)
        );
});

/**
 * The office list feeds the office filter on the tally sheet, which both roles
 * use, so reading it is not restricted. Changing it is: offices are part of
 * Settings, and the matrix gives settings.manage to HR Admin only.
 */
it('shows HR Staff the office list without the controls to change it', function (): void {
    $staff = hrStaff();
    Office::factory()->create(['name' => 'City Treasurer']);

    $this->actingAs($staff)
        ->get(route('offices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('offices', 1)
            ->where('can.manage', false)
        );
});

it('refuses every office change to HR Staff', function (string $method, string $route): void {
    $staff = hrStaff();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    $url = $route === 'offices.store' ? route($route) : route($route, $office);

    $this->actingAs($staff)->{$method}($url, ['name' => 'Renamed'])->assertForbidden();

    expect($office->refresh()->name)->toBe('City Treasurer');
})->with([
    ['post', 'offices.store'],
    ['patch', 'offices.update'],
    ['delete', 'offices.destroy'],
]);

it('adds an office', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->from(route('offices.index'))
        ->post(route('offices.store'), ['name' => '  City Treasurer  '])
        ->assertRedirect(route('offices.index'))
        ->assertSessionHas('success', 'City Treasurer has been added.');

    expect(Office::query()->sole()->name)->toBe('City Treasurer');
});

it('refuses an office with no name or a name already taken', function (array $payload, string $message): void {
    $admin = hrAdmin();
    Office::factory()->create(['name' => 'City Treasurer']);

    $this->actingAs($admin)
        ->from(route('offices.index'))
        ->post(route('offices.store'), $payload)
        ->assertSessionHasErrors('name');

    expect(Office::query()->count())->toBe(1);
})->with([
    'no name' => [['name' => ''], 'required'],
    'a duplicate name' => [['name' => 'City Treasurer'], 'unique'],
]);

it('renames an office', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    $this->actingAs($admin)
        ->from(route('offices.index'))
        ->patch(route('offices.update', $office), ['name' => 'Office of the City Treasurer'])
        ->assertSessionHas('success', 'Office of the City Treasurer has been renamed.');

    expect($office->refresh()->name)->toBe('Office of the City Treasurer');
});

it('lets an office keep its own name on rename', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    $this->actingAs($admin)
        ->patch(route('offices.update', $office), ['name' => 'City Treasurer'])
        ->assertSessionHasNoErrors();
});

it('removes an empty office', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    $this->actingAs($admin)
        ->from(route('offices.index'))
        ->delete(route('offices.destroy', $office))
        ->assertSessionHas('success', 'City Treasurer has been removed.');

    expect(Office::query()->count())->toBe(0);
});

it('explains why an office with people in it cannot be removed', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->inOffice($office)->create();

    // The foreign key would stop it anyway; the policy says so in words first.
    $this->actingAs($admin)
        ->delete(route('offices.destroy', $office))
        ->assertForbidden();

    expect(Office::query()->whereKey($office->id)->exists())->toBeTrue();
});

it('still refuses to remove an office whose only employee was removed', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->inOffice($office)->create()->delete();

    // Soft-deleted employees keep their office, so the row is still referenced
    // and the count the page greys the button on has to agree with the policy.
    $this->actingAs($admin)
        ->delete(route('offices.destroy', $office))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('offices.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offices.0.employeesCount', 1));
});
