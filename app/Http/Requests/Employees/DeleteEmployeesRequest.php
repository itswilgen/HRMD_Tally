<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The employees ticked on the masterlist, on their way to being soft deleted.
 *
 * Whether the signed-in user may do this at all is EmployeePolicy::deleteMany's
 * decision. Which of the ticked rows actually go is EmployeeService::deleteMany's
 * — anyone with leave on file is kept. This class only checks that the payload
 * is a list of real, still-listed employee ids of a sane length.
 */
class DeleteEmployeesRequest extends FormRequest
{
    /**
     * Five pages of the masterlist. The screen can only tick one page at a
     * time, so this is here to bound a forged payload, not to limit HR.
     */
    private const MAX_IDS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('deleteMany', Employee::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],

            // whereNull('deleted_at') is the point of spelling the rule out:
            // the plain exists rule queries the table directly and ignores the
            // soft-delete scope, so without it a double-submitted dialog would
            // "delete" an already deleted row instead of saying it is gone.
            'ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Tick at least one employee to delete.',
            'ids.max' => 'Delete at most '.self::MAX_IDS.' employees at a time.',
            'ids.*.exists' => 'One of the ticked employees is no longer on the masterlist. Reload the page and try again.',
        ];
    }

    /**
     * The validated ids, as the list of ints the service expects.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var array<array-key, mixed> $ids */
        $ids = $this->validated()['ids'] ?? [];

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
