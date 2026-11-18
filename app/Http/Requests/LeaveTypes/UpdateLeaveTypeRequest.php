<?php

declare(strict_types=1);

namespace App\Http\Requests\LeaveTypes;

use App\Models\LeaveType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeaveTypeRequest extends FormRequest
{
    use LeaveTypeRules;

    public function authorize(): bool
    {
        $leaveType = $this->leaveType();

        return $leaveType !== null && ($this->user()?->can('update', $leaveType) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->leaveTypeRules($this->leaveType());
    }

    /**
     * A code already on records is locked (business rule 8): changing it would
     * rename every past cell on screen while the printed sheets keep the old
     * letters. Name and colour stay free to change.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $leaveType = $this->leaveType();

                if ($leaveType === null || $validator->errors()->has('code')) {
                    return;
                }

                if ($this->input('code') !== $leaveType->code && $leaveType->isInUse()) {
                    $validator->errors()->add(
                        'code',
                        "{$leaveType->code} is already on leave records or credit figures, so its code cannot change. Add a new type instead.",
                    );
                }
            },
        ];
    }

    private function leaveType(): ?LeaveType
    {
        $leaveType = $this->route('leaveType');

        return $leaveType instanceof LeaveType ? $leaveType : null;
    }
}
