<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as every page sees them, shared by HandleInertiaRequests.
 *
 * Deliberately not the model: that would ship the password hash's presence, the
 * remember token and timestamps to the browser. `can` is sent so the interface
 * can hide what the user may not do — the policies still decide on the server.
 *
 * @mixin User
 */
class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource->role();

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'initials' => $this->initials(),
            'emailVerifiedAt' => $this->resource->email_verified_at?->toIso8601String(),
            'role' => $role?->value,
            'roleLabel' => $role?->label(),
            'can' => $this->permissionFlags(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissionFlags(): array
    {
        $flags = [];

        foreach (Permission::cases() as $permission) {
            $flags[$permission->frontendKey()] = $this->resource->can($permission->value);
        }

        return $flags;
    }

    /**
     * First and last initial, for the avatar in the sidebar.
     */
    private function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->resource->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_substr((string) reset($words), 0, 1);
        $last = count($words) > 1 ? mb_substr((string) end($words), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
