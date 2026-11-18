<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Exports\LeaveCreditsSheet;
use App\Models\LeaveCredit;
use App\Models\Office;
use App\Models\User;
use App\Services\LeaveCreditExport;
use App\Services\LeaveCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel: one year's leave credits, granted · taken · left per employee and
 * leave code (PROJECT_CONTEXT 6.1, rule 22). Same filters and rows as the
 * credits page.
 */
class LeaveCreditExportController extends Controller
{
    public function __invoke(Request $request, LeaveCreditService $credits, LeaveCreditExport $export): BinaryFileResponse
    {
        $this->authorize('viewAny', LeaveCredit::class);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can(Permission::GenerateExports->value), 403);

        // 1,000 employees with all 15 leave codes (48 columns) measured at
        // 1.3 s and about 153 MB: over PHP's 128 MB default, so it is raised
        // for this request only.
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $year = $credits->resolveYear($request->query('year'));
        $search = trim((string) $request->query('search', ''));
        $letter = mb_strtoupper(trim((string) $request->query('letter', '')));
        $letter = preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '';
        $officeId = max(0, (int) $request->query('office', 0));

        $data = $export->build($year, $search, $letter, $officeId);
        $office = $this->officeName($officeId);

        activity('export')
            ->causedBy($user)
            ->event('exported')
            ->withProperties([
                'format' => 'xlsx',
                'what' => 'credits',
                'year' => $year,
                'office' => $office,
                'employees' => count($data['rows']),
            ])
            ->log("Exported the {$year} leave credits to Excel ({$office})");

        return Excel::download(
            new LeaveCreditsSheet(
                year: $year,
                types: $data['types'],
                rows: $data['rows'],
                exportedLine: 'Exported '.Carbon::now()->format('F j, Y g:i A').' by '.$user->name,
                filtersNote: $this->filtersNote($office, $letter, $search),
            ),
            sprintf('leave-credits-%d-%s.xlsx', $year, Str::slug($office)),
            ExcelFormat::XLSX,
        );
    }

    private function officeName(int $officeId): string
    {
        if ($officeId === 0) {
            return 'All offices';
        }

        $name = Office::query()->whereKey($officeId)->value('name');

        return is_string($name) ? $name : 'Unknown office';
    }

    /** Said on the sheet, so a filtered export is never taken for the whole year. */
    private function filtersNote(string $office, string $letter, string $search): string
    {
        $parts = $office === 'All offices' ? [] : ["Office: {$office}"];

        if ($letter !== '') {
            $parts[] = "Last names starting with {$letter}";
        }

        if ($search !== '') {
            $parts[] = "Matching \"{$search}\"";
        }

        return $parts === [] ? '' : 'Filtered: '.implode(' · ', $parts);
    }
}
