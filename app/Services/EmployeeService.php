<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
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
     * Removing records created by mistake, over a ticked selection.
     *
     * "Delete" is a soft delete: the row leaves every list but stays in the
     * table, so an HR Admin can have it back and the activity log still points
     * at something real (business rule 9). Deleting never erases anything —
     * that is eraseMany below, a separate permission on a separate screen.
     *
     * An employee with leave already on file is kept rather than removed. Their
     * days are printed on tally sheets that must keep reading correctly, and
     * taking the row away would leave those sheets pointing at nothing;
     * deactivating is the right tool for someone who has left. The caller gets
     * their names back so the screen can say who was kept and why.
     *
     * One query loads the selection with its leave counts, so ticking fifty
     * rows costs the same two round trips as ticking one.
     *
     * @param  list<int>  $ids
     * @return array{deleted: list<string>, kept: list<string>}
     */
    public function deleteMany(array $ids, User $user): array
    {
        $employees = Employee::query()
            ->withCount('leaveRecords')
            ->whereIn('id', $ids)
            ->orderedByName()
            ->get();

        $kept = [];
        $removable = [];

        foreach ($employees as $employee) {
            if ((int) $employee->leave_records_count > 0) {
                $kept[] = $employee->list_name;

                continue;
            }

            $removable[] = $employee;
        }

        if ($removable === []) {
            return ['deleted' => [], 'kept' => $kept];
        }

        return DB::transaction(function () use ($removable, $kept, $user): array {
            $deleted = [];

            foreach ($removable as $employee) {
                $employee->delete();
                $deleted[] = $employee->list_name;
            }

            // The names go in the properties, not just the count: once the row
            // is soft deleted the log entry is the only place the activity page
            // can read who it was without resurrecting the record.
            activity('employee')
                ->causedBy($user)
                ->event('deleted')
                ->withProperties(['count' => count($deleted), 'employees' => $deleted])
                ->log(sprintf(
                    'Deleted %d employee %s from the masterlist',
                    count($deleted),
                    count($deleted) === 1 ? 'record' : 'records',
                ));

            return ['deleted' => $deleted, 'kept' => $kept];
        });
    }

    /**
     * Putting a deleted record back on the masterlist, which is what makes the
     * delete above safe to offer: the mistake HR corrects by deleting a row is
     * sometimes itself the mistake.
     *
     * The employee returns deactivated or active exactly as they were — the
     * delete never touched is_active — so a restored record does not quietly
     * rejoin leave encoding if it had already been taken off it.
     */
    public function restore(Employee $employee, User $user): Employee
    {
        return DB::transaction(function () use ($employee, $user): Employee {
            $employee->restore();

            activity('employee')
                ->causedBy($user)
                ->performedOn($employee)
                ->event('restored')
                ->log(sprintf('Restored %s to the masterlist', $employee->list_name));

            return $employee;
        });
    }

    /**
     * Erasing deleted records for good: the row leaves the table.
     *
     * This is the exception to business rule 9 and the only irreversible action
     * in the system, so it is hedged three ways. It reads from the deleted
     * records alone, never the live masterlist, so erasing is always a second
     * decision taken after the first one has been slept on. It refuses anyone
     * with leave on file for the same reason deleteMany does — their days are
     * printed on tally sheets that must keep reading correctly — and checks
     * that again here rather than trusting the ids the screen sent. And the
     * activity log keeps the names, so what was erased and by whom survives the
     * record itself; the log entry is all that is left to read afterwards.
     *
     * Erasing frees the employee number for reissue. That is safe only because
     * of the leave check above: a number is dangerous to reuse when a leave
     * history hangs off it, and an erased record has none.
     *
     * One query loads the selection with its leave counts, as deleteMany does.
     *
     * @param  list<int>  $ids
     * @return array{erased: list<string>, kept: list<string>}
     */
    public function eraseMany(array $ids, User $user): array
    {
        $employees = Employee::onlyTrashed()
            // withTrashed, unlike deleteMany's count: a deleted leave record is
            // still a row in leave_records, employee_id is restrictOnDelete, and
            // those records are recoverable in their own right. Counting only
            // the live ones would both destroy a leave history that could still
            // be restored and walk into a foreign key violation.
            ->withCount(['leaveRecords' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', $ids)
            ->orderedByName()
            ->get();

        $kept = [];
        $erasable = [];

        foreach ($employees as $employee) {
            if ((int) $employee->leave_records_count > 0) {
                $kept[] = $employee->list_name;

                continue;
            }

            $erasable[] = $employee;
        }

        if ($erasable === []) {
            return ['erased' => [], 'kept' => $kept];
        }

        return DB::transaction(function () use ($erasable, $kept, $user): array {
            $erased = [];

            foreach ($erasable as $employee) {
                // The number and the position go in the log with the name: once
                // the row is gone this entry is the only record that this
                // person was ever on the masterlist at all.
                $erased[] = sprintf('%s (%s)', $employee->list_name, $employee->employee_no);

                $employee->forceDelete();
            }

            activity('employee')
                ->causedBy($user)
                ->event('force_deleted')
                ->withProperties(['count' => count($erased), 'employees' => $erased])
                ->log(sprintf(
                    'Permanently erased %d employee %s',
                    count($erased),
                    count($erased) === 1 ? 'record' : 'records',
                ));

            return ['erased' => $erased, 'kept' => $kept];
        });
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
            'item_no' => $this->nullIfBlank($data['item_no'] ?? null),
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $this->nullIfBlank($data['middle_name'] ?? null),
            'suffix' => $this->nullIfBlank($data['suffix'] ?? null),
            'position_title' => $data['position_title'],
            'salary_grade' => $data['salary_grade'] ?? null,
            // Blank where HR has not assigned one, and where an import could
            // not know: the plantilla sheet it reads names no office. The
            // select submits "" for the office nobody chose, which is not an id.
            'office_id' => is_numeric($data['office_id'] ?? null) ? (int) $data['office_id'] : null,
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
