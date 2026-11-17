<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Services\LeaveCreditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules shared by everything that types credit figures: the per-employee
 * form, the grid, and the grant-to-all dialog.
 *
 * A credits map is leave type id => days, and **null means "no figure"** —
 * clearing a cell, or leaving one blank. Nought is different: it is a figure HR
 * chose. The services downstream honour the same distinction, so it is
 * validated here once rather than re-decided per screen.
 *
 * Two things the rules cannot say on their own live in the after-hook:
 * a key that is not a leave type at all (only a hand-edited request produces
 * one), and a figure against a retired leave type. The second is business
 * rule 8's shape again — a retired type keeps its history but accepts nothing
 * new — so an existing figure on one may still be corrected or cleared, while
 * granting a fresh one is refused.
 */
abstract class CreditValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', LeaveCredit::class) ?? false;
    }

    /**
     * Where this request's credits maps sit in the payload, each paired with
     * the employee the map is about — or null when it is about nobody in
     * particular (granting to all), in which case every figure is "new" and a
     * retired type takes none.
     *
     * @return array<string, int|null> payload path => employee id
     */
    abstract protected function creditMaps(): array;

    /**
     * @return list<mixed>
     */
    protected function yearRules(): array
    {
        return [
            'required',
            'integer',
            // The same clamp as the year picker, so a hand-edited request
            // cannot write credits onto a year the screens never offer.
            Rule::in(app(LeaveCreditService::class)->selectableYears()),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function creditsRules(string $path = 'credits'): array
    {
        return [
            $path => ['required', 'array'],
            $path.'.*' => ['nullable', 'numeric', 'min:0', 'max:999', 'multiple_of:0.5'],
        ];
    }

    /**
     * One credits map, typed: leave type id => days, null for a cleared cell.
     *
     * @return array<int, float|null>
     */
    public function daysByType(string $path = 'credits'): array
    {
        $map = $this->input($path);

        if (! is_array($map)) {
            return [];
        }

        $days = [];

        foreach ($map as $leaveTypeId => $value) {
            $days[(int) $leaveTypeId] = $value === null || $value === ''
                ? null
                : (float) $value;
        }

        return $days;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // A bad year would make the "already on file" lookup below
                // answer about the wrong sheet, so it stops the hook early.
                if ($validator->errors()->has('year')) {
                    return;
                }

                $maps = $this->creditMaps();

                if ($maps === []) {
                    return;
                }

                $types = LeaveType::query()->get(['id', 'code', 'is_active'])->keyBy('id');

                /** @var list<array{attribute: string, employeeId: int, typeId: int, code: string}> $retired */
                $retired = [];

                foreach ($maps as $path => $employeeId) {
                    $map = $this->input($path);

                    if (! is_array($map)) {
                        continue; // the base array rule has already complained
                    }

                    foreach ($map as $key => $value) {
                        $type = is_numeric($key) ? $types->get((int) $key) : null;

                        if ($type === null) {
                            $validator->errors()->add("{$path}.{$key}", 'That column is not a leave type.');

                            continue;
                        }

                        if ($value === null || $value === '' || $type->is_active) {
                            continue;
                        }

                        if ($employeeId === null) {
                            $validator->errors()->add(
                                "{$path}.{$key}",
                                "{$type->code} has been retired, so new credits cannot be granted against it.",
                            );

                            continue;
                        }

                        $retired[] = [
                            'attribute' => "{$path}.{$key}",
                            'employeeId' => $employeeId,
                            'typeId' => $type->id,
                            'code' => $type->code,
                        ];
                    }
                }

                if ($retired === []) {
                    return;
                }

                // One query for however many retired-type figures the payload
                // holds: a figure that is already on file is a correction and
                // may stand, one that is not is a new grant and may not.
                $existing = LeaveCredit::query()
                    ->forYear($this->integer('year'))
                    ->whereIn('employee_id', array_unique(array_column($retired, 'employeeId')))
                    ->whereIn('leave_type_id', array_unique(array_column($retired, 'typeId')))
                    ->toBase()
                    ->get(['employee_id', 'leave_type_id'])
                    ->map(static fn (mixed $row): string => data_get($row, 'employee_id').':'.data_get($row, 'leave_type_id'));

                $onFile = array_fill_keys($existing->all(), true);

                foreach ($retired as $candidate) {
                    if (! isset($onFile[$candidate['employeeId'].':'.$candidate['typeId']])) {
                        $validator->errors()->add(
                            $candidate['attribute'],
                            "{$candidate['code']} has been retired. Its existing figures can still be corrected or cleared, but a new one cannot be granted.",
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (['credits.*', 'rows.*.credits.*'] as $cell) {
            $messages["{$cell}.numeric"] = 'Enter the days as a number, like 15 or 7.5.';
            $messages["{$cell}.min"] = 'Credits cannot be negative.';
            $messages["{$cell}.max"] = 'No leave type grants more than 999 days in a year.';
            $messages["{$cell}.multiple_of"] = 'Credits are counted in half days — use steps of 0.5.';
        }

        $messages['year.in'] = 'Choose a year from the list.';

        return $messages;
    }

    /**
     * An error about a cell names the leave code HR sees on the column, not
     * "credits.7".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (LeaveType::query()->pluck('code', 'id') as $id => $code) {
            $attributes["credits.{$id}"] = (string) $code;
            $attributes["rows.*.credits.{$id}"] = (string) $code;
        }

        return $attributes;
    }
}
