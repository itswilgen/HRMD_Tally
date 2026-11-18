<?php

declare(strict_types=1);

namespace App\Http\Requests\Holidays;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or editing one holiday (PROJECT_CONTEXT section 5.3).
 *
 * Beyond the field rules, two checks need the database:
 *  - one holiday per day, counting fixed-date holidays on their month and day
 *    in every year, because HolidayCalendar would otherwise hold two names for
 *    one date and keep only one of them;
 *  - leave already encoded on the new date, which is allowed but has to be
 *    confirmed, since those records were saved when the day was a working day.
 */
class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $holiday = $this->holiday();

        return $holiday === null
            ? ($this->user()?->can('create', Holiday::class) ?? false)
            : ($this->user()?->can('update', $holiday) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::enum(HolidayKind::class)],
            'confirm_encoded' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Pick the date of the holiday.',
            'date.date_format' => 'Pick the date of the holiday.',
            'date.after_or_equal' => 'Pick a date from the year 2000 on.',
            'date.before_or_equal' => 'Pick a date before the year 2101.',
            'kind.required' => 'Pick the kind of holiday.',
            'kind.enum' => 'Pick one of the kinds shown.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // date_format:Y-m-d has passed, so this parses; the null check
                // keeps a parser failure from becoming a crash instead.
                $date = Carbon::createFromFormat('Y-m-d', (string) $this->input('date'))?->startOfDay();
                $kind = HolidayKind::tryFrom((string) $this->input('kind'));

                if ($date === null || $kind === null) {
                    $validator->errors()->add('date', 'Pick the date of the holiday.');

                    return;
                }

                $clash = $this->clash($date, $kind);

                if ($clash !== null) {
                    $validator->errors()->add('date', $clash);

                    return;
                }

                if (! $this->movesToNewDays($date, $kind) || $this->boolean('confirm_encoded')) {
                    return;
                }

                $leave = $this->leaveOn($date, $kind);

                if ($leave['records'] > 0) {
                    $validator->errors()->add('confirm_encoded', sprintf(
                        '%s already %s leave on %s (%s). The holiday will not change %s: check %s on the tally. Tick the box to save anyway.',
                        $this->plural($leave['employees'], 'employee', 'employees'),
                        $leave['employees'] === 1 ? 'has' : 'have',
                        $kind->recursYearly() ? $date->format('F j').' (in '.$this->plural($leave['years'], 'year', 'years').')' : $date->format('D, F j, Y'),
                        $this->plural($leave['records'], 'record', 'records'),
                        $leave['records'] === 1 ? 'it' : 'them',
                        $leave['records'] === 1 ? 'it' : 'them',
                    ));
                }
            },
        ];
    }

    /**
     * Why this date cannot take the holiday, or null when it can.
     */
    private function clash(Carbon $date, HolidayKind $kind): ?string
    {
        $monthDay = $date->format('m-d');

        if ($kind->recursYearly() && $monthDay === '02-29') {
            return 'A holiday every year cannot be on February 29, which most years do not have. Choose another kind and enter it for this year only.';
        }

        $others = Holiday::query()->when(
            $this->holiday() !== null,
            fn (Builder $query) => $query->whereKeyNot($this->holiday()?->getKey()),
        );

        // A fixed-date holiday already on this month and day, in any year.
        $fixed = (clone $others)
            ->recurring()
            ->whereRaw("to_char(date, 'MM-DD') = ?", [$monthDay])
            ->first();

        if ($fixed !== null) {
            return "{$date->format('F j')} is already {$fixed->name}, every year.";
        }

        // A dated holiday on this exact date, or, for a fixed-date holiday,
        // on this month and day in any year it would now repeat into.
        $dated = (clone $others)
            ->where('recurs_yearly', false)
            ->when(
                $kind->recursYearly(),
                fn (Builder $query) => $query->whereRaw("to_char(date, 'MM-DD') = ?", [$monthDay]),
                fn (Builder $query) => $query->whereDate('date', $date->toDateString()),
            )
            ->orderBy('date')
            ->first();

        if ($dated !== null) {
            return "{$dated->date->format('D, F j, Y')} is already {$dated->name}. One day can hold only one holiday.";
        }

        return null;
    }

    /**
     * Whether this save makes days non-working that were not before. An edit
     * that only renames, or keeps the same day, needs no new confirmation.
     */
    private function movesToNewDays(Carbon $date, HolidayKind $kind): bool
    {
        $holiday = $this->holiday();

        if ($holiday === null) {
            return true;
        }

        $before = $holiday->recurs_yearly ? 'every-'.$holiday->date->format('m-d') : $holiday->date->format('Y-m-d');
        $after = $kind->recursYearly() ? 'every-'.$date->format('m-d') : $date->format('Y-m-d');

        if ($before === $after) {
            return false;
        }

        // A fixed-date holiday narrowed to one year of the same day adds nothing.
        return ! ($holiday->recurs_yearly && ! $kind->recursYearly() && $holiday->date->format('m-d') === $date->format('m-d'));
    }

    /**
     * Live leave records on the day (or, for a fixed-date holiday, on that
     * month and day in every year).
     *
     * @return array{records: int, employees: int, years: int}
     */
    private function leaveOn(Carbon $date, HolidayKind $kind): array
    {
        $row = LeaveRecord::query()
            ->when(
                $kind->recursYearly(),
                fn (Builder $query) => $query->whereRaw("to_char(leave_date, 'MM-DD') = ?", [$date->format('m-d')]),
                fn (Builder $query) => $query->whereDate('leave_date', $date->toDateString()),
            )
            ->toBase()
            ->selectRaw('count(*) as records, count(distinct employee_id) as employees, count(distinct extract(year from leave_date)) as years')
            ->first();

        return [
            'records' => (int) ($row->records ?? 0),
            'employees' => (int) ($row->employees ?? 0),
            'years' => (int) ($row->years ?? 0),
        ];
    }

    private function plural(int $count, string $one, string $many): string
    {
        return number_format($count).' '.($count === 1 ? $one : $many);
    }

    private function holiday(): ?Holiday
    {
        $holiday = $this->route('holiday');

        return $holiday instanceof Holiday ? $holiday : null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => preg_replace('/\s+/', ' ', trim($name))]);
        }
    }
}
