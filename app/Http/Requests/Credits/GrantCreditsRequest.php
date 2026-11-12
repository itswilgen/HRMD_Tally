<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * "Give everybody this much for the year."
 *
 * The standard yearly grant is the same figure for almost everyone, so this is
 * how a blank year gets filled in one go; the per-employee exceptions are then
 * corrected by hand. A blank cell in this dialog grants nothing rather than
 * granting nought — see LeaveCreditService::grantToAll().
 */
class GrantCreditsRequest extends CreditValuesRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => $this->yearRules(),
            ...$this->creditsRules(),

            // Optional, and the dialog says what it means: granting office by
            // office is how HR works through a year when the figures differ by
            // office, and leaving it empty grants to the whole masterlist.
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],

            'overwrite' => ['nullable', 'boolean'],
        ];
    }

    public function officeId(): ?int
    {
        return $this->filled('office_id') ? $this->integer('office_id') : null;
    }

    public function overwrite(): bool
    {
        return $this->boolean('overwrite');
    }

    /**
     * Null, not an employee id: granting to all is about nobody in particular,
     * so every figure in it is a new grant and a retired leave type takes none.
     *
     * @return array<string, int|null>
     */
    protected function creditMaps(): array
    {
        return ['credits' => null];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'office_id.exists' => 'That office is no longer on the list.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'office_id' => 'office',
        ];
    }
}
