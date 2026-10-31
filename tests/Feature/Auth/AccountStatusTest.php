<?php

declare(strict_types=1);

use App\Models\User;

/**
 * A deactivated account keeps every record it encoded but may not sign in, and
 * must not stay signed in: on a shared office PC, checking only at login would
 * leave a revoked account working until someone happened to log out.
 */
it('refuses a sign-in from a deactivated account', function (): void {
    $user = User::factory()->deactivated()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('says why the sign-in was refused rather than calling the password wrong', function (): void {
    $user = User::factory()->deactivated()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors([
        'email' => 'This account has been deactivated. Ask an HR Admin to restore it.',
    ]);
});

it('still refuses a deactivated account the usual wrong-password error', function (): void {
    $user = User::factory()->deactivated()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('lets an active account sign in', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('ends the session of an account deactivated while it was signed in', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['is_active' => false]);

    $this->get('/dashboard')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('explains the sign-out to the account that was deactivated mid-session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    $user->update(['is_active' => false]);

    $this->get('/dashboard')->assertSessionHas(
        'error',
        'This account has been deactivated. Ask an HR Admin to restore it.'
    );
});

it('does not sign out an account that is still active', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/profile')->assertOk();

    $this->assertAuthenticatedAs($user);
});
