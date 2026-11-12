<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The credits grid's save: many employees, one year.
 *
 * The page sends only the rows whose cells were actually touched, so a save
 * after changing one figure is one row — and the cap below is a bound on a
 * hand-built request rather than on how HR works, since the grid never shows
 * more than a page of employees at a time.
 */
class SaveCreditsGridRequest extends CreditValuesRequest
{
    /**
     * A page of the grid is 25 employees. Four pages' worth is generous for
     * anything the screen can produce and small enough that one request stays
     * one transaction HR does not wait on.
     */
    private const MAX_ROWS = 100;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => $this->yearRules(),

            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],

            // Not is_active: the grid lists anyone active *or* with leave on
            // file for the year (business rule 7), so a resigned employee's
            // past credits must stay editable. Soft-deleted is a different
            // matter — their record is in the bin and nothing should be
            // written against it.
            'rows.*.employee_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],

            'rows.*.credits' => ['required', 'array'],
            'rows.*.credits.*' => ['nullable', 'numeric', 'min:0', 'max:999', 'multiple_of:0.5'],
        ];
    }

    /**
     * The grid's figures as the service takes them: employee id => (leave type
     * id => days).
     *
     * @return array<int, array<int, float|null>>
     */
    public function daysByEmployee(): array
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return [];
        }

        $byEmployee = [];

        foreach (array_keys($rows) as $index) {
            $employeeId = (int) $this->input("rows.{$index}.employee_id");

            $byEmployee[$employeeId] = $this->daysByType("rows.{$index}.credits");
        }

        return $byEmployee;
    }

    /**
     * @return array<string, int|null>
     */
    protected function creditMaps(): array
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return [];
        }

        $maps = [];

        foreach (array_keys($rows) as $index) {
            $maps["rows.{$index}.credits"] = (int) $this->input("rows.{$index}.employee_id");
        }

        return $maps;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'rows.required' => 'Nothing has changed yet.',
            'rows.max' => 'Too many employees in one save. Save a page at a time.',
            'rows.*.employee_id.exists' => 'One of those employees is no longer on the masterlist. Reload the page and try again.',
            'rows.*.employee_id.distinct' => 'That employee appears twice in the same save.',
        ];
    }
}
