<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Enums\DayPart;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The rules shared by saving a leave range and previewing one.
 *
 * Both have to agree exactly: a preview that accepted a range the save then
 * rejected would send the encoder back to a form they had already confirmed.
 */
abstract class LeaveRangeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // Deactivated and soft-deleted employees are off the encoding list
            // (business rule 7), so they cannot be the subject of new leave
            // even if the id is posted by hand.
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],

            // A retired leave type keeps its history but accepts nothing new
            // (business rule 8).
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where('is_active', true),
            ],

            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'day_part' => ['required', Rule::enum(DayPart::class)],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];

        // A half day is half of *one* day. Allowing a half-day range would let
        // one keystroke write twenty 0.5-day rows, which is never what an
        // approved leave form says — two separate half days are two entries.
        if ($this->input('day_part') !== DayPart::Whole->value) {
            $rules['date_to'][] = 'same:date_from';
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee is not on the encoding list.',
            'leave_type_id.exists' => 'Choose a leave type that is still in use.',
            'date_from.date_format' => 'Enter the first day of the leave.',
            'date_to.date_format' => 'Enter the last day of the leave.',
            'date_to.after_or_equal' => 'The last day cannot be before the first day.',
            'date_to.same' => 'A half day covers one date only. Encode each half day separately.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee',
            'leave_type_id' => 'leave type',
            'date_from' => 'first day',
            'date_to' => 'last day',
            'day_part' => 'whole or half day',
        ];
    }

    protected function prepareForValidation(): void
    {
        // A single-date entry posts only date_from; mirroring it here keeps one
        // set of rules for both shapes instead of a second branch for each.
        if ($this->input('date_to') === null && is_string($this->input('date_from'))) {
            $this->merge(['date_to' => $this->input('date_from')]);
        }

        if (is_string($this->input('remarks'))) {
            $remarks = trim($this->input('remarks'));

            $this->merge(['remarks' => $remarks === '' ? null : $remarks]);
        }
    }
}
