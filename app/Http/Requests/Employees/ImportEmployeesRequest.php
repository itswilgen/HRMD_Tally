<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A masterlist CSV, for previewing or for saving. Whoever may add one employee
 * may add many; the rows themselves are checked by EmployeeImportService
 * against the same rules as the Add employee form.
 */
class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // extensions checks the name HR sees, mimes checks the contents: a
            // workbook renamed to .csv is still a workbook.
            'file' => ['required', 'file', 'extensions:csv', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the CSV file to import.',
            'file.extensions' => 'Choose a .csv file. In Excel, use File › Save As › CSV UTF-8.',
            'file.mimes' => 'This file is not plain CSV. In Excel, use File › Save As › CSV UTF-8.',
            'file.max' => 'The file is larger than 2 MB. Split it into smaller files.',
        ];
    }
}
