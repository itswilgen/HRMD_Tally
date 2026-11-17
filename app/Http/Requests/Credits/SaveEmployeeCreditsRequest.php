<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One employee's credits for one year, from the per-employee screen.
 */
class SaveEmployeeCreditsRequest extends CreditValuesRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => $this->yearRules(),
            ...$this->creditsRules(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    protected function creditMaps(): array
    {
        $employee = $this->route('employee');

        return ['credits' => $employee instanceof Employee ? $employee->id : null];
    }
}
