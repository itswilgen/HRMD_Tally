<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;

class StoreEmployeeRequest extends EmployeeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }
}
