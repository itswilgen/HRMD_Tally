<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /*
     * The starter kit let anyone delete their own account from this page, which
     * would have let the last HR Admin lock the office out of the system and
     * taken an encoder's name off the records they had encoded. Removal now
     * goes through UserPolicy only, so the route must stay gone.
     */
    public function test_an_account_cannot_be_deleted_from_the_profile_page()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_self_delete_route_is_not_registered()
    {
        $this->assertFalse(
            Route::has('profile.destroy'),
            'profile.destroy is back: self-deletion bypasses UserPolicy.'
        );
    }
}
