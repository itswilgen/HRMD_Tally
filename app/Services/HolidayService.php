<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The holiday calendar HR keeps on screen (PROJECT_CONTEXT section 5.3).
 *
 * Reading a year goes through HolidayCalendar's rule — dated rows as they are,
 * fixed-date rows projected onto the year — so the page lists exactly the days
 * encoding will skip. Every write is in the activity log under "holiday".
 */
class HolidayService
{
    /**
     * Every holiday in the year, in date order, with the leave already on
     * file for each day.
     *
     * @return list<array{id: int, date: string, name: string, kind: string, kindLabel: string, recursYearly: bool, leaveRecords: int, leaveEmployees: int}>
     */
    public function forYear(int $year): array
    {
        $rows = Holiday::query()
            ->where(fn ($query) => $query->forYear($year)->orWhere('recurs_yearly', true))
            ->get();

        $holidays = [];

        foreach ($rows as $holiday) {
            $date = $holiday->recurs_yearly
                ? $this->projectOnto($holiday->date, $year)
                : $holiday->date->format('Y-m-d');

            if ($date === null) {
                continue;
            }

            $holidays[] = [
                'id' => (int) $holiday->id,
                'date' => $date,
                'name' => $holiday->name,
                'kind' => $holiday->kind->value,
                'kindLabel' => $holiday->kind->shortLabel(),
                'recursYearly' => $holiday->recurs_yearly,
                'leaveRecords' => 0,
                'leaveEmployees' => 0,
            ];
        }

        usort($holidays, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        // One grouped query for the whole year, not one per holiday.
        $leave = $holidays === [] ? collect() : LeaveRecord::query()
            ->whereIn('leave_date', array_column($holidays, 'date'))
            ->toBase()
            ->selectRaw("to_char(leave_date, 'YYYY-MM-DD') as day, count(*) as records, count(distinct employee_id) as employees")
            ->groupByRaw("to_char(leave_date, 'YYYY-MM-DD')")
            ->get()
            ->keyBy('day');

        foreach ($holidays as $index => $holiday) {
            $row = $leave->get($holiday['date']);

            if ($row !== null) {
                $holidays[$index]['leaveRecords'] = (int) $row->records;
                $holidays[$index]['leaveEmployees'] = (int) $row->employees;
            }
        }

        return $holidays;
    }

    /**
     * @param  array{date: string, name: string, kind: string}  $data
     */
    public function create(array $data, User $user): Holiday
    {
        return DB::transaction(function () use ($data, $user): Holiday {
            $holiday = Holiday::query()->create($this->attributes($data));

            activity('holiday')
                ->causedBy($user)
                ->performedOn($holiday)
                ->event('created')
                ->withProperties(['attributes' => $this->snapshot($holiday)])
                ->log("Added holiday {$holiday->name} ({$this->when($holiday)})");

            return $holiday;
        });
    }

    /**
     * A save that changes nothing logs nothing.
     *
     * @param  array{date: string, name: string, kind: string}  $data
     */
    public function update(Holiday $holiday, array $data, User $user): Holiday
    {
        return DB::transaction(function () use ($holiday, $data, $user): Holiday {
            $before = $this->snapshot($holiday);
            $attributes = $this->attributes($data);

            // A fixed-date holiday is edited from whichever year is on screen,
            // so the form sends that year. Only its month and day mean
            // anything: keep the stored date when those did not change.
            if ($holiday->recurs_yearly
                && $attributes['kind']->recursYearly()
                && Carbon::parse($attributes['date'])->format('m-d') === $holiday->date->format('m-d')) {
                $attributes['date'] = $holiday->date->format('Y-m-d');
            }

            $holiday->fill($attributes);

            // The date cast compares Carbon instances, so a resent date counts
            // as dirty; compare what the log would say instead.
            $after = $this->snapshot($holiday);
            $changed = array_keys(array_diff_assoc($after, $before));

            if ($changed === []) {
                return $holiday;
            }

            $holiday->save();

            activity('holiday')
                ->causedBy($user)
                ->performedOn($holiday)
                ->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log("Edited holiday {$holiday->name} ({$this->when($holiday)})");

            return $holiday;
        });
    }

    public function delete(Holiday $holiday, User $user): void
    {
        DB::transaction(function () use ($holiday, $user): void {
            $snapshot = $this->snapshot($holiday);
            $when = $this->when($holiday);

            $holiday->delete();

            activity('holiday')
                ->causedBy($user)
                ->event('deleted')
                ->withProperties(['old' => $snapshot])
                ->log("Removed holiday {$snapshot['name']} ({$when})");
        });
    }

    /**
     * @param  array{date: string, name: string, kind: string}  $data
     * @return array{date: string, name: string, kind: HolidayKind}
     */
    private function attributes(array $data): array
    {
        return [
            'date' => $data['date'],
            'name' => $data['name'],
            'kind' => HolidayKind::from($data['kind']),
        ];
    }

    /**
     * @return array{date: string, name: string, kind: string}
     */
    private function snapshot(Holiday $holiday): array
    {
        return [
            'date' => Carbon::parse($holiday->date)->format('Y-m-d'),
            'name' => $holiday->name,
            'kind' => $holiday->kind->value,
        ];
    }

    /** "every April 9" or "Thu, April 9, 2027", as the log should read. */
    private function when(Holiday $holiday): string
    {
        $date = Carbon::parse($holiday->date);

        return $holiday->kind->recursYearly()
            ? 'every '.$date->format('F j')
            : $date->format('D, F j, Y');
    }

    /** HolidayCalendar's projection, so the page and encoding agree. */
    private function projectOnto(Carbon $date, int $year): ?string
    {
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
