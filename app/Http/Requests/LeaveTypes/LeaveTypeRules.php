<?php

declare(strict_types=1);

namespace App\Http\Requests\LeaveTypes;

use App\Enums\LeaveChipColor;
use App\Models\LeaveType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The rules a leave type's code, name and colour share between adding and
 * editing (business rule 8), so the two forms cannot drift apart.
 */
trait LeaveTypeRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function leaveTypeRules(?LeaveType $ignore = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique(LeaveType::class, 'code')->ignore($ignore?->getKey()),
            ],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique(LeaveType::class, 'name')->ignore($ignore?->getKey()),
            ],
            'color' => ['required', Rule::enum(LeaveChipColor::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters and numbers only, with no spaces, e.g. SL or VAWC.',
            'code.max' => 'A code has at most 10 characters.',
            'code.unique' => 'Another leave type already uses this code.',
            'name.unique' => 'Another leave type already uses this name.',
            'color.required' => 'Pick a colour for the chip.',
            'color.enum' => 'Pick one of the colours shown.',
        ];
    }

    /**
     * Codes are written in capitals on the sheet, so "sl" is saved as "SL"
     * rather than refused; spaces around either field are dropped.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $name = $this->input('name');

        $this->merge(array_filter([
            'code' => is_string($code) ? mb_strtoupper(trim($code)) : null,
            'name' => is_string($name) ? preg_replace('/\s+/', ' ', trim($name)) : null,
        ], static fn (?string $value): bool => $value !== null));
    }
}
