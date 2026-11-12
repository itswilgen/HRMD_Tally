<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeaveCreditFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One leave credit figure: this employee, this leave type, this year.
 *
 * Phase 1 does not compute credits — it records the figure an HR Admin types
 * from the leave card, including for years already on file, and the credits
 * screens show it against the days actually taken. Earning rules, monthly
 * accrual, the COC caps and the CSC leave card are still a later phase.
 *
 * There is no SoftDeletes here, unlike every other record in the system. A
 * credit is a figure rather than an event: HR changes it by overwriting it and
 * removes it by clearing the field, and the activity log keeps what it said
 * before. Nothing is printed off the back of a credit row the way a tally sheet
 * is printed off leave_records, so there is no past reading of it to protect.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $year
 * @property float $days
 * @property string|null $remarks
 * @property int|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read User|null $grantor
 */
class LeaveCredit extends Model
{
    /** @use HasFactory<LeaveCreditFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'days',
        'remarks',
        'granted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',

            // float, not decimal:1, because every credit figure is added to and
            // subtracted from leave day totals that are floats themselves.
            // decimal:1 would hand back the string "15.0" and arithmetic on it
            // would quietly stringify. Halves are exact in binary floating
            // point, which is the only precision this column ever needs.
            'days' => 'float',
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
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
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
