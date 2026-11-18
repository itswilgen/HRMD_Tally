<?php

declare(strict_types=1);

use App\Models\Signatory;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
| Settings → Signatories (PROJECT_CONTEXT 5.7): who signs the printed tally,
| left to right. Starts empty; an HR Admin adds the real people.
*/

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function signatoryPayload(array $overrides = []): array
{
    return [
        'label' => 'Prepared by',
        'name' => 'Ana Reyes',
        'position' => 'Administrative Aide IV',
        ...$overrides,
    ];
}

function signatory(string $name, int $order): Signatory
{
    return Signatory::query()->create([
        'label' => 'Prepared by',
        'name' => $name,
        'position' => 'Administrative Aide IV',
        'sort_order' => $order,
    ]);
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('signatories.index'))->assertRedirect(route('login'));
});

it('lists the signatories in print order', function (): void {
    signatory('Second', 2);
    signatory('First', 1);

    $this->actingAs(hrAdmin())
        ->get(route('signatories.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/signatories')
            ->has('signatories', 2)
            ->where('signatories.0.name', 'First')
            ->where('signatories.1.name', 'Second')
            ->where('can.manage', true)
        );
});

it('shows HR Staff who signs, without the controls to change it', function (): void {
    signatory('First', 1);

    $this->actingAs(hrStaff())
        ->get(route('signatories.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('signatories', 1)->where('can.manage', false));
});

it('refuses every signatory change to HR Staff', function (string $method, string $route): void {
    $signatory = signatory('First', 1);
    $url = $route === 'signatories.store' ? route($route) : route($route, $signatory);

    $this->actingAs(hrStaff())->{$method}($url, [...signatoryPayload(), 'direction' => 'right'])->assertForbidden();

    expect(Signatory::query()->count())->toBe(1)
        ->and($signatory->fresh()?->name)->toBe('First')
        ->and(Activity::query()->where('log_name', 'signatory')->exists())->toBeFalse();
})->with([
    'add' => ['post', 'signatories.store'],
    'edit' => ['patch', 'signatories.update'],
    'move' => ['patch', 'signatories.move'],
    'remove' => ['delete', 'signatories.destroy'],
]);

it('adds a signatory at the end, tidies spaces, and logs it', function (): void {
    signatory('First', 1);
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->post(route('signatories.store'), signatoryPayload(['name' => '  Ma. Lourdes   dela Peña ', 'label' => 'Certified correct by']))
        ->assertSessionHasNoErrors();

    $added = Signatory::query()->ordered()->get()->last();

    expect($added?->name)->toBe('Ma. Lourdes dela Peña')
        ->and($added?->label)->toBe('Certified correct by')
        ->and($added?->sort_order)->toBe(2);

    $log = Activity::query()->where('log_name', 'signatory')->sole();

    expect($log->event)->toBe('created')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->description)->toBe('Added signatory Ma. Lourdes dela Peña (Certified correct by)');
});

it('validates a signatory', function (array $data, string $field): void {
    $this->actingAs(hrAdmin())
        ->post(route('signatories.store'), signatoryPayload($data))
        ->assertSessionHasErrors($field);

    expect(Signatory::query()->exists())->toBeFalse();
})->with([
    'no line' => [['label' => ' '], 'label'],
    'a line over 60 characters' => [['label' => str_repeat('a', 61)], 'label'],
    'no name' => [['name' => ''], 'name'],
    'a name over 150 characters' => [['name' => str_repeat('a', 151)], 'name'],
    'no position' => [['position' => ''], 'position'],
]);

it('edits a signatory and logs only what changed', function (): void {
    $signatory = signatory('Ana Reyes', 1);

    $this->actingAs(hrAdmin())
        ->patch(route('signatories.update', $signatory), signatoryPayload(['position' => 'Administrative Officer II']))
        ->assertSessionHasNoErrors();

    expect($signatory->fresh()?->position)->toBe('Administrative Officer II');

    $log = Activity::query()->where('log_name', 'signatory')->sole();

    expect($log->properties['old'])->toBe(['position' => 'Administrative Aide IV'])
        ->and($log->properties['attributes'])->toBe(['position' => 'Administrative Officer II']);
});

it('logs nothing when an edit changes nothing', function (): void {
    $signatory = signatory('Ana Reyes', 1);

    $this->actingAs(hrAdmin())
        ->patch(route('signatories.update', $signatory), signatoryPayload())
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'signatory')->exists())->toBeFalse();
});

it('moves a signatory left and right, and not past either end', function (): void {
    $first = signatory('First', 1);
    $second = signatory('Second', 2);
    $third = signatory('Third', 3);
    $admin = hrAdmin();

    $this->actingAs($admin)->patch(route('signatories.move', $third), ['direction' => 'left'])->assertSessionHasNoErrors();
    expect(Signatory::query()->ordered()->pluck('name')->all())->toBe(['First', 'Third', 'Second']);

    $this->actingAs($admin)->patch(route('signatories.move', $first), ['direction' => 'left'])->assertSessionHasNoErrors();
    expect(Signatory::query()->ordered()->pluck('name')->all())->toBe(['First', 'Third', 'Second']);

    $this->actingAs($admin)->patch(route('signatories.move', $second), ['direction' => 'right'])->assertSessionHasNoErrors();
    expect(Signatory::query()->ordered()->pluck('name')->all())->toBe(['First', 'Third', 'Second'])
        ->and(Activity::query()->where('log_name', 'signatory')->where('event', 'reordered')->count())->toBe(1);
});

it('refuses a move with no direction', function (): void {
    $signatory = signatory('First', 1);

    $this->actingAs(hrAdmin())
        ->patch(route('signatories.move', $signatory), ['direction' => 'up'])
        ->assertSessionHasErrors('direction');
});

it('removes a signatory and logs who it was', function (): void {
    $signatory = signatory('Ana Reyes', 1);

    $this->actingAs(hrAdmin())
        ->delete(route('signatories.destroy', $signatory))
        ->assertSessionHasNoErrors();

    expect(Signatory::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('log_name', 'signatory')->sole()->description)->toBe('Removed signatory Ana Reyes (Prepared by)');
});
