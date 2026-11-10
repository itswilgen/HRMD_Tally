<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The records ticked on the deleted employees list, on their way to being
 * erased for good.
 *
 * Whether the signed-in user may do this at all is
 * EmployeePolicy::forceDeleteMany's decision, and which of the ticked rows
 * actually go is EmployeeService::eraseMany's — anyone with leave on file is
 * kept. This class checks that the payload is a list of ids that are really
 * sitting in the deleted records, of a sane length.
 */
class EraseEmployeesRequest extends FormRequest
{
    /**
     * Deliberately lower than DeleteEmployeesRequest's hundred. Erasing cannot
     * be undone, and HR has no reason to destroy more than a page of mistaken
     * rows in one go; a request for more is a sign something is wrong.
     */
    private const MAX_IDS = 50;

    public function authorize(): bool
    {
        return $this->user()?->can('forceDeleteMany', Employee::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],

            // whereNotNull('deleted_at') is the rule that keeps erasing to the
            // second of two decisions: an id taken off the live masterlist, by
            // a forged payload or by a stale screen, fails validation rather
            // than destroying a record nobody has deleted yet.
            'ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->whereNotNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Tick at least one deleted record to erase.',
            'ids.max' => 'Erase at most '.self::MAX_IDS.' records at a time.',
            'ids.*.exists' => 'One of the ticked records is not in the deleted records any more — it may have been restored or already erased. Reload the page and try again.',
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
