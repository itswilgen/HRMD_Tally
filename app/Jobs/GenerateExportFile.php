<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportFile;
use App\Services\ExportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one background export (PROJECT_CONTEXT 5.7) with the same
 * ExportBuilder as a straight download, and stores the file privately
 * until its owner downloads it.
 */
class GenerateExportFile implements ShouldQueue
{
    use Queueable;

    /** One try: a failed sheet is reported, not rebuilt in a loop. */
    public int $tries = 1;

    /** Ten minutes; 1,000 employees measured at 10 seconds. */
    public int $timeout = 600;

    public function __construct(public readonly string $exportId) {}

    public function handle(ExportBuilder $builder): void
    {
        $export = ExportFile::query()->with('user')->find($this->exportId);

        // Deleted while it waited (kept-days clean-up): nothing to build.
        if ($export === null || $export->status !== ExportFile::STATUS_QUEUED) {
            return;
        }

        $export->update(['status' => ExportFile::STATUS_RUNNING, 'started_at' => Carbon::now()]);

        /** @var array{year: int, search: string, letter: string, office: int} $filters */
        $filters = $export->filters;
        $bytes = $builder->build($export->kind, $filters, $export->user);
        $path = ExportFile::DIRECTORY.'/'.$export->id.'.'.$export->kind->extension();

        Storage::disk('local')->put($path, $bytes);

        $export->update([
            'status' => ExportFile::STATUS_DONE,
            'path' => $path,
            'size' => strlen($bytes),
            'finished_at' => Carbon::now(),
        ]);
    }

    /**
     * Shown on Downloads in plain words; the details go to the log.
     */
    public function failed(?Throwable $exception): void
    {
        ExportFile::query()->whereKey($this->exportId)->update([
            'status' => ExportFile::STATUS_FAILED,
            'error' => 'The file could not be made. Try again, or choose one office at a time.',
            'finished_at' => Carbon::now(),
        ]);
    }
}
