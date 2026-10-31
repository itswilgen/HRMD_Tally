<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Models\LeaveRecord;

class StoreLeaveRecordRequest extends LeaveRangeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRecord::class) ?? false;
    }

    /**
     * The validated range, in the shape LeaveEncodingService::store() takes.
     *
     * Read field by field rather than handed over as validated() wholesale, so
     * the types are the request's own promise instead of an annotation the
     * controller makes on its behalf.
     *
     * @return array{employee_id: int, leave_type_id: int, date_from: string, date_to: string, day_part: string, remarks: string|null}
     */
    public function range(): array
    {
        return [
            'employee_id' => $this->integer('employee_id'),
            'leave_type_id' => $this->integer('leave_type_id'),
            'date_from' => $this->string('date_from')->toString(),
            'date_to' => $this->string('date_to')->toString(),
            'day_part' => $this->string('day_part')->toString(),
            'remarks' => $this->filled('remarks') ? $this->string('remarks')->toString() : null,
        ];
    }
}
