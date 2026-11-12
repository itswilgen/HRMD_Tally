<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "Copy last year's figures onto this one."
 *
 * There is no credits map here: the figures come from the source year's rows,
 * so the only decisions are which year to read, which to write, and whether a
 * figure already in the target year may be replaced.
 */
class CopyCreditsRequest extends CreditValuesRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => $this->yearRules(),
            'from_year' => [...$this->yearRules(), 'different:year'],

            // The dialog's checkbox. Absent means unticked, which is the safe
            // reading: copying fills blanks and leaves figures alone.
            'overwrite' => ['nullable', 'boolean'],
        ];
    }

    public function fromYear(): int
    {
        return $this->integer('from_year');
    }

    public function year(): int
    {
        return $this->integer('year');
    }

    public function overwrite(): bool
    {
        return $this->boolean('overwrite');
    }

    /**
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
            'from_year.in' => 'Choose a year from the list to copy from.',
            'from_year.different' => 'Choose a different year to copy from.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'from_year' => 'year to copy from',
            'year' => 'year to copy to',
        ];
    }
}
