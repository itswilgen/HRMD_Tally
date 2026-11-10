<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A filled-in masterlist, for previewing or for saving. The file is the whole
 * request: everything about the employees, the office included, is a column in
 * it. Whoever may add one employee may add many; the rows themselves are
 * checked by EmployeeImportService against the same rules as the Add employee
 * form.
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
            // extensions checks the name HR sees; mimetypes checks the bytes,
            // so neither a renamed file nor a mislabelled one gets through on
            // the other's say-so.
            //
            // application/zip is allowed alongside the real workbook type
            // because an .xlsx is a ZIP archive underneath, and an older
            // libmagic without the OOXML signature reports it as one. Letting
            // that through costs nothing: a renamed .zip gets as far as
            // EmployeeImportService, which cannot open it and says so.
            'file' => [
                'required',
                'file',
                'extensions:xlsx,csv',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,text/csv,text/plain',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the file to import.',
            'file.extensions' => 'Choose an .xlsx or .csv file. Download the template and fill that in.',
            'file.mimetypes' => 'This file is not an Excel workbook or a CSV. Download the template, fill it in, and choose it here.',
            'file.max' => 'The file is larger than 2 MB. Split it into smaller files.',
        ];
    }
}
