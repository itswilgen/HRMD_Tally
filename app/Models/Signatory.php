<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One person who signs the printed tally sheet (PROJECT_CONTEXT 5.7).
 *
 * @property int $id
 * @property string $label the line above the name, e.g. "Prepared by"
 * @property string $name
 * @property string $position
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Signatory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'name',
        'position',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Left to right on the sheet.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
