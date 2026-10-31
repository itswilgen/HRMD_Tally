<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $encoded_leave_records_count
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Leave records this user encoded.
     *
     * @return HasMany<LeaveRecord, $this>
     */
    public function encodedLeaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class, 'encoded_by');
    }

    public function isHrAdmin(): bool
    {
        return $this->hasRole(UserRole::HrAdmin->value);
    }

    public function isHrStaff(): bool
    {
        return $this->hasRole(UserRole::HrStaff->value);
    }

    /**
     * Every account has exactly one role, so the collection spatie returns is
     * collapsed to a single enum for the rest of the app to read.
     *
     * Null means the account was created without a role, which the user form
     * prevents but a half-finished seed or an import could still produce.
     *
     * Not an Eloquent relation despite the name — `roles` is the relation, and
     * this reads from it. Load it eagerly before calling this in a loop.
     */
    public function role(): ?UserRole
    {
        $name = $this->roles->pluck('name')->first();

        return is_string($name) ? UserRole::tryFrom($name) : null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrderedByName(Builder $query): void
    {
        $query->orderByRaw('lower(name)');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('lower(name) LIKE ?', [$like])
                ->orWhereRaw('lower(email) LIKE ?', [$like]);
        });
    }

    /**
     * Account changes are audited. The password hash is never logged, and
     * `logOnlyDirty` keeps a save that changed nothing out of the log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
