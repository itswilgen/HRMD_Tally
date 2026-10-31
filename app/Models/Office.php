<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property-read int|null $employees_count set by withCount(), absent otherwise
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
