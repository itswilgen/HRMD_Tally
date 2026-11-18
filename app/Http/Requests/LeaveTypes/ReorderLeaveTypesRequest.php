<?php

declare(strict_types=1);

namespace App\Http\Requests\LeaveTypes;

use App\Models\LeaveType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The whole order at once, not "move this one up": the page sends what it
 * shows, so two admins reordering at the same time cannot leave a gap or a
 * duplicate, and the list must name every type exactly once.
 */
class ReorderLeaveTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', LeaveType::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:200'],
            // No `distinct` here: a repeated id is the same mistake as a
            // missing one, and the check below says so once, on `ids`.
            'ids.*' => ['required', 'integer'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $given = $this->ids();
                $all = LeaveType::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

                sort($given);
                sort($all);

                if ($given !== $all) {
                    $validator->errors()->add('ids', 'The list of leave types changed while you were ordering it. Reload the page and try again.');
                }
            },
        ];
    }

    /**
     * The ids in the order sent. Called after validation, so every entry is
     * an integer; the digit check still skips anything else rather than trust it.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ((array) $this->input('ids', []) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
