<?php

declare(strict_types=1);

namespace App\Http\Requests\Signatories;

use App\Models\Signatory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or editing one signatory. Spaces are tidied; capitals are left as
 * typed, because a name like "Ma. Lourdes dela Peña" is HR's to spell.
 */
class SignatoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $signatory = $this->route('signatory');

        return $signatory instanceof Signatory
            ? ($this->user()?->can('update', $signatory) ?? false)
            : ($this->user()?->can('create', Signatory::class) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Type the line they sign under, e.g. Prepared by.',
            'name.required' => 'Type the full name as it should print.',
            'position.required' => 'Type the position, e.g. City Human Resource Management Officer.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $tidy = [];

        foreach (['label', 'name', 'position'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $tidy[$field] = preg_replace('/\s+/', ' ', trim($value));
            }
        }

        $this->merge($tidy);
    }
}
