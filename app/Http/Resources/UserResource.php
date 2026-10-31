<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user account as the account management table shows it.
 *
 * @mixin User
 */
class UserResource extends JsonResource
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
            'role' => $role?->value,
            'roleLabel' => $role?->label(),
            'isActive' => $this->resource->is_active,
            'createdAt' => $this->resource->created_at?->toDateString(),
            // Counted, not loaded: the table only needs to know whether the
            // account is referenced by leave records, which is what stops it
            // from being deleted. Zero when the count was not requested.
            'encodedLeaveRecordsCount' => (int) ($this->resource->encoded_leave_records_count ?? 0),
            // Lets the table grey out actions the viewer would be refused, and
            // say why, instead of offering a button that returns a 403.
            'isSelf' => $request->user()?->is($this->resource) ?? false,
        ];
    }
}
