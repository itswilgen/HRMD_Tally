<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Enums\DayPart;
use App\Models\LeaveRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing one already-encoded day.
 *
 * The employee is not editable: moving a day from one person to another is two
 * different corrections to two different records, and allowing it here would
 * make the activity log read as though one entry had simply been amended.
 */
class UpdateLeaveRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LeaveRecord|null $record */
        $record = $this->route('leave_record');

        return $record !== null && ($this->user()?->can('update', $record) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where('is_active', true),
            ],
            'leave_date' => ['required', 'date_format:Y-m-d'],
            'day_part' => ['required', Rule::enum(DayPart::class)],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_id.exists' => 'Choose a leave type that is still in use.',
            'leave_date.date_format' => 'Enter the date of the leave.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'leave_type_id' => 'leave type',
            'leave_date' => 'date',
            'day_part' => 'whole or half day',
        ];
    }

    /**
     * The correction, in the shape LeaveEncodingService::update() takes.
     *
     * @return array{leave_type_id: int, leave_date: string, day_part: string, remarks: string|null}
     */
    public function changes(): array
    {
        return [
            'leave_type_id' => $this->integer('leave_type_id'),
            'leave_date' => $this->string('leave_date')->toString(),
            'day_part' => $this->string('day_part')->toString(),
            'remarks' => $this->filled('remarks') ? $this->string('remarks')->toString() : null,
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remarks'))) {
            $remarks = trim($this->input('remarks'));

            $this->merge(['remarks' => $remarks === '' ? null : $remarks]);
        }
    }
}
