<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Roles and permissions
|--------------------------------------------------------------------------
|
| RefreshDatabase empties the roles and permissions tables between tests, so
| anything that touches authorization has to put them back. RoleSeeder is used
| rather than a hand-written set, which means a test fails if the real matrix
| in UserRole::permissions() stops granting what the feature needs.
|
*/

function seedRolesAndPermissions(): void
{
    (new RoleSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(UserRole $role, array $attributes = []): User
{
    seedRolesAndPermissions();

    $user = User::factory()->create($attributes);
    $user->syncRoles([$role->value]);

    return $user->fresh() ?? $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function hrAdmin(array $attributes = []): User
{
    return userWithRole(UserRole::HrAdmin, $attributes);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function hrStaff(array $attributes = []): User
{
    return userWithRole(UserRole::HrStaff, $attributes);
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
