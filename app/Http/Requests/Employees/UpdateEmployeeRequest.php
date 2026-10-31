<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;

class UpdateEmployeeRequest extends EmployeeRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('update', $employee) ?? false);
    }
}
