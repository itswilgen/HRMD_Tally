<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HolidayKind;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $name
 * @property HolidayKind $kind
 * @property bool $recurs_yearly whether the date repeats every year; always derived from $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'name',
        'kind',
        'recurs_yearly',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => HolidayKind::class,
            'recurs_yearly' => 'boolean',
        ];
    }

    /**
     * Keeps `recurs_yearly` honest.
     *
     * The column is what queries read, but only the kind decides whether a
     * date repeats, so the two can never be set independently — a row saying
     * "special non-working day, recurs every year" would quietly add a day off
     * to every future year.
     */
    protected static function booted(): void
    {
        static::saving(function (self $holiday): void {
            $holiday->recurs_yearly = $holiday->kind->recursYearly();
        });
    }

    /**
     * The handful of holidays matched on month and day instead of on the full
     * date, so they are entered once rather than every year.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->where('recurs_yearly', true);
    }

    /**
     * Date-range bounded, never YEAR(date), so the unique index is used.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->whereBetween('date', [
            sprintf('%d-01-01', $year),
            sprintf('%d-12-31', $year),
        ]);
    }
}
