<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing employee records.
 *
 * Two of the columns are privilege-gated — monthly_rate and employment_status —
 * and the Form Requests drop them from the validated data when the signed-in
 * user may not set them. Every write here therefore asks whether the key is
 * present, never whether its value is empty: a field HR Staff never saw must
 * keep what an HR Admin put there, while a field an HR Admin deliberately
 * cleared must actually be cleared.
 */
class EmployeeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $employee = new Employee;

            $employee->fill($this->attributes($data));
            $employee->is_active = true;
            $employee->save();

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data): Employee {
            $employee->fill($this->attributes($data));
            $employee->save();

            return $employee;
        });
    }

    /**
     * Deactivating takes an employee off the encoding list and out of this
     * year's tally without touching what has already been recorded for them.
     */
    public function setActive(Employee $employee, bool $isActive): Employee
    {
        $employee->is_active = $isActive;
        $employee->save();

        return $employee;
    }

    /**
     * The employment statuses already in use, most used first.
     *
     * HR chose free text over a fixed list, so the only thing keeping the
     * spelling consistent is showing an HR Admin what their colleagues have
     * already typed. Reads one indexed column and groups in the database, so
     * the list costs the same whether there are 80 employees or 8,000.
     *
     * @return list<string>
     */
    public function employmentStatusSuggestions(): array
    {
        return array_values(array_filter(
            Employee::query()
                ->whereNotNull('employment_status')
                ->groupBy('employment_status')
                ->orderByRaw('count(*) desc')
                ->orderBy('employment_status')
                ->pluck('employment_status')
                ->all(),
            static fn (mixed $status): bool => is_string($status),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'employee_no' => $data['employee_no'],
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $this->nullIfBlank($data['middle_name'] ?? null),
            'suffix' => $this->nullIfBlank($data['suffix'] ?? null),
            'position_title' => $data['position_title'],
            'salary_grade' => $data['salary_grade'] ?? null,
            'office_id' => $data['office_id'],
        ];

        if (array_key_exists('monthly_rate', $data)) {
            $attributes['monthly_rate'] = $data['monthly_rate'];
        }

        if (array_key_exists('employment_status', $data)) {
            $attributes['employment_status'] = $this->nullIfBlank($data['employment_status']);
        }

        return $attributes;
    }

    /**
     * An HTML text input submits "" for a field the person cleared, and an empty
     * string in a nullable column reads back as a value that is there but says
     * nothing. Blank means blank.
     */
    private function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
