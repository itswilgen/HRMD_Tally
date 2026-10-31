<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayPart;
use Database\Factories\LeaveRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One row per leave day. A date range is expanded into many of these.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $leave_date
 * @property DayPart $day_part
 * @property string|null $remarks
 * @property int|null $encoded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read User|null $encoder
 */
class LeaveRecord extends Model
{
    /** @use HasFactory<LeaveRecordFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'leave_date',
        'day_part',
        'remarks',
        'encoded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'day_part' => DayPart::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    /**
     * Leave days this record consumes: 1 for a whole day, 0.5 for a half day.
     */
    public function days(): float
    {
        return $this->day_part->days();
    }

    /**
     * Bounded date range rather than YEAR(leave_date), so the
     * (employee_id, leave_date) index is used.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->whereBetween('leave_date', [
            sprintf('%d-01-01', $year),
            sprintf('%d-12-31', $year),
        ]);
    }

    /**
     * @param  Builder<$this>  $query
     * @param  array<int, int>  $employeeIds
     */
    public function scopeForEmployees(Builder $query, array $employeeIds): void
    {
        $query->whereIn('employee_id', $employeeIds);
    }
}
