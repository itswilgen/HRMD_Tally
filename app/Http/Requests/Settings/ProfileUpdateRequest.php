<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     *
     * @throws AuthenticationException
     */
    public function rules(): array
    {
        $user = $this->user();

        // The profile routes are behind the `auth` middleware. Without this the
        // unique rule would fall back to ignoring no one and reject the user's
        // own email address.
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ];
    }
}
