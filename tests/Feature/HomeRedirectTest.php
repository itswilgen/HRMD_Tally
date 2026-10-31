<?php

declare(strict_types=1);

use App\Models\User;

/**
 * There is no public landing page for an internal office tool: the root URL
 * puts you where you were going.
 */
it('sends a guest from the root URL to the sign-in page', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends a signed-in user from the root URL to the dashboard', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('has no marketing page left behind', function (): void {
    expect(app('router')->has('welcome'))->toBeFalse()
        ->and(file_exists(resource_path('js/pages/welcome.tsx')))->toBeFalse();
});
