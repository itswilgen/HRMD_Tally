<?php

declare(strict_types=1);

namespace App\Http\Requests\Offices;

use App\Models\Office;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && ($this->user()?->can('update', $office) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $office = $this->route('office');

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique(Office::class, 'name')
                    ->ignore($office instanceof Office ? $office->getKey() : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another office already uses this name.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }
}
