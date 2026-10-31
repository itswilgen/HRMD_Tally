<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Which calendar days the office is closed, for one year at a time.
 *
 * This is the single answer to "is this a working day?" (business rule 2), used
 * when a leave range is expanded and when the encoder previews the dates it
 * will save. Keeping it in one place is what stops the preview and the save
 * from ever disagreeing.
 *
 * Holidays are read once per year per instance and held in memory. They are not
 * cached between requests on purpose: the table holds a few dozen rows, so a
 * query costs nothing, whereas a stale cache would silently mis-expand every
 * range encoded after HR adds a newly proclaimed holiday.
 */
class HolidayCalendar
{
    /**
     * Resolved holidays, keyed by year and then by Y-m-d.
     *
     * @var array<int, array<string, string>>
     */
    private array $byYear = [];

    /**
     * Every holiday that falls in the given year, as 'Y-m-d' => name.
     *
     * Dated rows are taken as they are; the seven fixed-date regular holidays
     * are stored once and projected onto the year being asked about, which is
     * what lets HR enter them once instead of every January.
     *
     * @return array<string, string>
     */
    public function forYear(int $year): array
    {
        if (array_key_exists($year, $this->byYear)) {
            return $this->byYear[$year];
        }

        $holidays = [];

        $rows = Holiday::query()
            ->where(function ($query) use ($year): void {
                $query->forYear($year)->orWhere('recurs_yearly', true);
            })
            ->get(['date', 'name', 'recurs_yearly']);

        foreach ($rows as $row) {
            if (! $row->recurs_yearly) {
                $holidays[$row->date->format('Y-m-d')] = $row->name;

                continue;
            }

            $month = (int) $row->date->format('n');
            $day = (int) $row->date->format('j');

            // Guards a 29 February rule against non-leap years. None of the
            // seven fixed holidays falls there, but a future one entered by
            // hand should not blow up the whole calendar.
            if (! checkdate($month, $day, $year)) {
                continue;
            }

            $holidays[sprintf('%04d-%02d-%02d', $year, $month, $day)] = $row->name;
        }

        ksort($holidays);

        return $this->byYear[$year] = $holidays;
    }

    /**
     * A Saturday, a Sunday, or a holiday — a day no leave is charged against.
     */
    public function isNonWorkingDay(CarbonInterface $date): bool
    {
        return $date->isWeekend() || $this->isHoliday($date);
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        return $this->nameFor($date) !== null;
    }

    /**
     * The holiday's name, or null on an ordinary day. Used to tell the encoder
     * *why* a date was skipped rather than just dropping it.
     */
    public function nameFor(CarbonInterface $date): ?string
    {
        return $this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')] ?? null;
    }

    /**
     * Working days in a month, which the tally uses to show how much of a
     * month an employee was actually away for.
     */
    public function workingDaysInMonth(int $year, int $month): int
    {
        $cursor = Carbon::create($year, $month, 1)?->startOfDay();

        if ($cursor === null) {
            return 0;
        }

        $days = 0;
        $lastDay = (int) $cursor->copy()->endOfMonth()->format('j');

        for ($day = 1; $day <= $lastDay; $day++) {
            if (! $this->isNonWorkingDay($cursor->copy()->setDay($day))) {
                $days++;
            }
        }

        return $days;
    }
}
