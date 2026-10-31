<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use App\Models\Office;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The rules an employee record is held to, shared by adding and editing.
 *
 * The two privilege-gated fields are handled here rather than in the service:
 * if the signed-in user may not set monthly_rate or employment_status, the rule
 * is simply not registered, so `validated()` cannot contain the key no matter
 * what the browser posted. That makes a forged request a no-op instead of a
 * forbidden response, which is the right outcome for a field the person's own
 * form never showed them.
 */
abstract class EmployeeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'employee_no' => [
                'required', 'string', 'max:50',
                // No withoutTrashed(): the rule queries the table as it stands,
                // so a soft-deleted employee keeps their number. Reusing it
                // would attach their leave history to someone new.
                Rule::unique('employees', 'employee_no')->ignore($this->employeeId()),
            ],
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'position_title' => ['required', 'string', 'max:150'],

            // Salary grades run 1-33 under the Salary Standardization Law.
            // Nullable because job order personnel do not have one.
            'salary_grade' => ['nullable', 'integer', 'min:1', 'max:33'],

            'office_id' => ['required', 'integer', Rule::exists(Office::class, 'id')],
        ];

        if ($this->user()?->can('viewSalary', Employee::class) ?? false) {
            $rules['monthly_rate'] = ['nullable', 'numeric', 'min:0', 'max:9999999999'];
        }

        if ($this->user()?->can('setEmploymentStatus', Employee::class) ?? false) {
            $rules['employment_status'] = ['nullable', 'string', 'max:60'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_no.unique' => 'Another employee already has this employee number.',
            'office_id.required' => 'Please choose the office this employee reports to.',
            'office_id.exists' => 'That office no longer exists. Please choose another one.',
            'salary_grade.max' => 'Salary grades run from 1 to 33.',
            'salary_grade.min' => 'Salary grades run from 1 to 33.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_no' => 'employee number',
            'last_name' => 'last name',
            'first_name' => 'first name',
            'middle_name' => 'middle name',
            'position_title' => 'position title',
            'salary_grade' => 'salary grade',
            'office_id' => 'office',
            'monthly_rate' => 'monthly rate',
            'employment_status' => 'employment status',
        ];
    }

    /**
     * Names are typed from a paper form, so leading and trailing spaces arrive
     * with them. Trimming before validation means `max:100` counts characters
     * HR can actually see.
     */
    protected function prepareForValidation(): void
    {
        $trimmable = ['employee_no', 'last_name', 'first_name', 'middle_name', 'suffix', 'position_title', 'employment_status'];

        $merge = [];

        foreach ($trimmable as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $merge[$field] = trim($value);
            }
        }

        // An empty number input posts "", which is not an integer and not null.
        foreach (['salary_grade', 'monthly_rate'] as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * The record being edited, or null when one is being added.
     */
    protected function employeeId(): ?int
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? (int) $employee->getKey() : null;
    }
}
