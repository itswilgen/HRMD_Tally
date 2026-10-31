<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The signed-in user, guaranteed to exist.
     *
     * Request::user() is nullable because not every route is guarded. Controllers
     * that call this one sit behind the `auth` middleware, so a null user means
     * that middleware was removed by mistake: this turns it into a clean 401
     * instead of a "call to a member function on null" 500.
     *
     * @throws AuthenticationException
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
