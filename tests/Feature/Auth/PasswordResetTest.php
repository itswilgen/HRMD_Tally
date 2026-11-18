<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/**
 * Self-service password reset is removed on purpose: only an HR Admin sets a
 * password, under Settings → User accounts (see "it resets a password when one
 * is given" in UserManagementTest). These tests exist so nobody restores the
 * starter kit's forgot-password routes by accident.
 */
it('has no forgot-password page', function (): void {
    $this->get('/forgot-password')->assertNotFound();
});

it('sends no reset link, whatever is posted', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();

    Notification::assertNothingSent();
    Notification::assertNotSentTo($user, ResetPassword::class);
});

it('has no reset-password page or form', function (): void {
    $this->get('/reset-password/any-token')->assertNotFound();
    $this->post('/reset-password', [
        'token' => 'any-token',
        'email' => 'someone@example.com',
        'password' => 'Tally-Sheet-2026',
        'password_confirmation' => 'Tally-Sheet-2026',
    ])->assertNotFound();
});

it('has no named password reset routes', function (): void {
    foreach (['password.request', 'password.email', 'password.reset', 'password.store'] as $name) {
        expect(app('router')->has($name))->toBeFalse("{$name} should not exist");
    }
});

it('tells people on the login page to ask an HR Admin instead', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->missing('canResetPassword'));
});
