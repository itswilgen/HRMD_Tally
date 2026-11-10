<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Eloquent returns mixed for attributes, so the column types are declared here.
 * This is what lets static analysis catch a wrong comparison or a missing null
 * check instead of waving it through.
 *
 * @property int $id
 * @property string $employee_no
 * @property string|null $item_no the plantilla item this position is, when it holds one
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $suffix
 * @property string $position_title
 * @property int|null $salary_grade
 * @property string|null $monthly_rate decimal:2 is cast to string to avoid float rounding
 * @property int|null $office_id null until HR assigns one; a plantilla sheet names no office
 * @property string|null $employment_status free text an HR Admin types; null until they do
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int|null $leave_records_count set by withCount(), absent otherwise
 * @property-read string|null $middle_initial
 * @property-read string $list_name
 * @property-read string $full_name
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_no',
        'item_no',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'position_title',
        'salary_grade',
        'monthly_rate',
        'office_id',
        'employment_status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_grade' => 'integer',
            'monthly_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return HasMany<LeaveRecord, $this>
     */
    public function leaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    /**
     * Middle initial only, as written on the paper sheet.
     *
     * @return Attribute<string|null, never>
     */
    protected function middleInitial(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->middleInitialValue());
    }

    /**
     * "Dela Cruz, Juan P. Jr." — the alphabetical listing form.
     *
     * The last name is always present, which is why the generic is narrower than
     * the plain string of full_name below.
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function listName(): Attribute
    {
        return Attribute::get(function (): string {
            $given = implode(' ', array_filter([
                $this->first_name,
                $this->middleInitialValue(),
                $this->suffix,
            ]));

            return "{$this->last_name}, {$given}";
        });
    }

    /**
     * "Juan P. Dela Cruz Jr." — the natural reading form.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->first_name,
            $this->middleInitialValue(),
            $this->last_name,
            $this->suffix,
        ])));
    }

    /**
     * Shared by the three name accessors. Kept as a plain method so none of them
     * has to read another accessor's virtual attribute.
     */
    private function middleInitialValue(): ?string
    {
        $middleName = $this->middle_name;

        if ($middleName === null || trim($middleName) === '') {
            return null;
        }

        return mb_strtoupper(mb_substr($middleName, 0, 1)).'.';
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Alphabetical by last name, as on the paper sheet.
     *
     * lower() is required because PostgreSQL compares text case-sensitively;
     * it matches the employees_name_sort_index functional index.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrderedByName(Builder $query): void
    {
        $query->orderByRaw('lower(last_name), lower(first_name)');
    }

    /**
     * A-Z letter tabs. Matches employees_last_name_prefix_index.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStartingWith(Builder $query, string $letter): void
    {
        $query->whereRaw('lower(last_name) LIKE ?', [mb_strtolower($letter).'%']);
    }

    /**
     * Free-text search over name and position.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->whereRaw('lower(last_name) LIKE ?', [$like])
                ->orWhereRaw('lower(first_name) LIKE ?', [$like])
                ->orWhereRaw('lower(position_title) LIKE ?', [$like])
                ->orWhereRaw('lower(employee_no) LIKE ?', [$like]);
        });
    }
}
