<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The tally sheet's filters and the one answer to "who is on this sheet".
 *
 * The screen, the PDF and the Excel export all read their rows from here, so
 * a filter can never mean one thing on screen and another on paper (business
 * rule 6 for rows, as LeaveTallyService is for figures).
 */
final readonly class TallyFilters
{
    /**
     * How far back the year picker reaches. The system has no leave before it
     * was installed, so offering 1990 would only invite a mis-click; next year
     * is offered because leave is sometimes filed in advance.
     */
    private const YEARS_BACK = 5;

    public function __construct(
        public int $year,
        public string $search = '',
        public string $letter = '',
        public int $officeId = 0,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $years = self::selectableYears();
        $requested = (int) $request->query('year', (string) Carbon::now()->year);
        $letter = mb_strtoupper(trim((string) $request->query('letter', '')));

        return new self(
            year: in_array($requested, $years, true) ? $requested : Carbon::now()->year,
            search: trim((string) $request->query('search', '')),
            // A single letter only. Anything else is someone editing the URL,
            // and the honest answer to that is to ignore it rather than error.
            letter: preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '',
            officeId: max(0, (int) $request->query('office', 0)),
        );
    }

    /**
     * @return list<int>
     */
    public static function selectableYears(): array
    {
        $current = Carbon::now()->year;

        return range($current + 1, $current - self::YEARS_BACK);
    }

    /**
     * Everyone on the sheet, A–Z, before paging or grouping.
     *
     * Business rule 7: the people currently being encoded for, plus anyone
     * with leave on file for the year being looked at. That second half keeps
     * a resigned employee's days on the year they were still working.
     *
     * @return Builder<Employee>
     */
    public function employees(): Builder
    {
        $year = $this->year;

        return Employee::query()
            ->with('office:id,name')
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->letter !== '', fn (Builder $query) => $query->startingWith($this->letter))
            ->when($this->officeId > 0, fn (Builder $query) => $query->where('office_id', $this->officeId))
            ->where(function (Builder $query) use ($year): void {
                $query->where('is_active', true)
                    ->orWhereHas('leaveRecords', fn (Builder $records) => $records->forYear($year));
            })
            ->orderedByName();
    }

    /**
     * @return array{search: string, letter: string, office: string}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'letter' => $this->letter,
            'office' => $this->officeId > 0 ? (string) $this->officeId : '',
        ];
    }
}
