<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Public self-registration is removed on purpose: accounts are created by an HR
 * Admin under Settings → User accounts. These tests exist so nobody restores the
 * starter kit's register routes by accident.
 */
it('has no registration page', function (): void {
    $this->get('/register')->assertNotFound();
});

it('does not accept a registration post', function (): void {
    $this->post('/register', [
        'name' => 'Walk In',
        'email' => 'walkin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->where('email', 'walkin@example.com')->exists())->toBeFalse();
});

it('has no named register route', function (): void {
    expect(app('router')->has('register'))->toBeFalse();
});
