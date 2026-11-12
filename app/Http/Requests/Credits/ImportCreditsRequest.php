<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A filled-in credits sheet, for previewing or for saving.
 *
 * The year travels with the file rather than being read out of it: the template
 * is a download of one year's grid, and HR choosing 2025 in the dialog is a
 * clearer statement of intent than a cell in row 1 that could have been edited.
 *
 * The file rules mirror ImportEmployeesRequest exactly, including the
 * application/zip allowance — an .xlsx is a ZIP underneath, and an older
 * libmagic reports it as one.
 */
class ImportCreditsRequest extends CreditValuesRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => $this->yearRules(),
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
     * The rows are checked by LeaveCreditImportService against the same limits
     * the forms use, one row at a time, so the after-hook here has nothing to
     * look at.
     *
     * @return array<string, int|null>
     */
    protected function creditMaps(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'file.required' => 'Choose the file to import.',
            'file.extensions' => 'Choose an .xlsx or .csv file. Download the sheet for the year and fill that in.',
            'file.mimetypes' => 'This file is not an Excel workbook or a CSV. Download the sheet for the year, fill it in, and choose it here.',
            'file.max' => 'The file is larger than 2 MB. Import an office at a time.',
        ];
    }
}
