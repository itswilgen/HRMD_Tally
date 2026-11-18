<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One export built in the background, and its file (PROJECT_CONTEXT 5.7).
 *
 * @property string $id
 * @property int $user_id
 * @property ExportKind $kind
 * @property string $status one of the STATUS_ constants
 * @property array{year: int, search: string, letter: string, office: int} $filters
 * @property string $title
 * @property string $file_name
 * @property int $rows
 * @property string|null $path on the local (private) disk
 * @property int|null $size bytes
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ExportFile extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Where the files live on the private disk. */
    public const DIRECTORY = 'exports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'kind',
        'status',
        'filters',
        'title',
        'file_name',
        'rows',
        'path',
        'size',
        'error',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'filters' => 'array',
            'rows' => 'integer',
            'size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_DONE
            && $this->path !== null
            && Storage::disk('local')->exists($this->path);
    }

    /**
     * Deletes exports older than the keep period, with their files, and
     * marks as failed any that have waited longer than a worker would ever
     * take, so nobody watches "Preparing…" forever. Run when Downloads is
     * opened and when an export starts, so no scheduler is needed.
     */
    public static function tidy(): void
    {
        $keepDays = config('exports.keep_days', 7);
        $staleMinutes = config('exports.stale_after_minutes', 60);

        $expired = self::query()
            ->where('created_at', '<', Carbon::now()->subDays(is_numeric($keepDays) ? (int) $keepDays : 7))
            ->get();

        foreach ($expired as $export) {
            if ($export->path !== null) {
                Storage::disk('local')->delete($export->path);
            }

            $export->delete();
        }

        self::query()
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->where('created_at', '<', Carbon::now()->subMinutes(is_numeric($staleMinutes) ? (int) $staleMinutes : 60))
            ->update([
                'status' => self::STATUS_FAILED,
                'error' => 'It was never finished. The background worker may not be running: ask the system administrator.',
                'finished_at' => Carbon::now(),
            ]);
    }
}
