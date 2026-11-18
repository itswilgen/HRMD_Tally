<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\ExportFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads: the signed-in user's background exports, and their files
 * (PROJECT_CONTEXT 5.7). Only the person who asked for a file can see or
 * download it: a file can hold salary rates (RA 10173).
 */
class DownloadController extends Controller
{
    /** How many recent exports the page lists. */
    private const LIMIT = 30;

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        ExportFile::tidy();

        $exports = ExportFile::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (ExportFile $export): array => [
                'id' => $export->id,
                'kind' => $export->kind->value,
                'title' => $export->title,
                'fileName' => $export->file_name,
                'status' => $export->isReady() || $export->status !== ExportFile::STATUS_DONE ? $export->status : ExportFile::STATUS_FAILED,
                'rows' => $export->rows,
                'size' => $export->size,
                'error' => $export->status === ExportFile::STATUS_DONE && ! $export->isReady()
                    ? 'The file is no longer on the server. Export it again.'
                    : $export->error,
                'createdAt' => $export->created_at?->toIso8601String(),
                'finishedAt' => $export->finished_at?->toIso8601String(),
                'expiresAt' => $export->created_at?->copy()->addDays((int) config('exports.keep_days', 7))->toIso8601String(),
            ])
            ->all();

        return Inertia::render('downloads/index', [
            'exports' => $exports,
            'highlight' => is_string($request->query('highlight')) ? $request->query('highlight') : null,
        ]);
    }

    public function download(Request $request, ExportFile $export): StreamedResponse
    {
        $user = $this->user($request);

        // Someone else's file is not found, rather than forbidden: the link
        // says nothing about whose it is or that it exists.
        abort_unless($export->user_id === $user->id, 404);
        abort_unless($export->isReady() && $export->path !== null, 404);

        return Storage::disk('local')->response(
            $export->path,
            $export->file_name,
            ['Content-Type' => $export->kind->mimeType(), 'Cache-Control' => 'private, no-store'],
            $export->kind->extension() === 'pdf' ? 'inline' : 'attachment',
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(Permission::GenerateExports->value), 403);

        return $user;
    }
}
