<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Models\LeaveRecord;

/**
 * Asks what a range would save, without saving it.
 *
 * Gated on `create` rather than on viewing the tally: the answer describes an
 * employee's existing leave on the conflicting dates, which is not something to
 * hand to anyone who merely has read access to a page.
 */
class PreviewLeaveDatesRequest extends LeaveRangeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRecord::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Set when previewing an edit, so the record being changed is not
            // reported as conflicting with itself.
            'ignore_record_id' => ['nullable', 'integer'],

            // A preview is advisory, so the leave type is not needed to answer
            // "which dates would this cover".
            'leave_type_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @return array{employee_id: int, date_from: string, date_to: string, day_part: string, ignore_record_id: int|null}
     */
    public function range(): array
    {
        return [
            'employee_id' => $this->integer('employee_id'),
            'date_from' => $this->string('date_from')->toString(),
            'date_to' => $this->string('date_to')->toString(),
            'day_part' => $this->string('day_part')->toString(),
            'ignore_record_id' => $this->filled('ignore_record_id') ? $this->integer('ignore_record_id') : null,
        ];
    }
}
