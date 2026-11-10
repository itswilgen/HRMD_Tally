<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can(Permission::ViewTally->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageEmployees->value);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can(Permission::ManageEmployees->value);
    }

    /**
     * Taking someone off the encoding list. Reversible, and nothing they have
     * already been credited with on the tally sheet changes.
     */
    public function deactivate(User $user, Employee $employee): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    public function activate(User $user, Employee $employee): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    /**
     * "Delete" means soft-delete: the row leaves the lists and its leave
     * history stays intact. It is for a record created by mistake — a
     * duplicate, a typo imported twice — not for someone who has left the city
     * government, who is deactivated instead. Erasing such a row for good is
     * forceDelete below, which is a different permission.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    /**
     * The masterlist's tick-boxes, which are delete() offered over a selection.
     *
     * Asked once for the whole batch rather than once per ticked row, because
     * delete() above does not vary by employee. What does vary per employee is
     * whether the row may go at all, and that is EmployeeService::deleteMany's
     * call: anyone with leave already on file is kept.
     */
    public function deleteMany(User $user): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    /**
     * Reading the deleted records at all, which is its own question: the list
     * holds names HR has already taken off the masterlist, and only the role
     * that can put them back or erase them has a reason to see it.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->can(Permission::DeactivateEmployees->value);
    }

    /**
     * Erasing a record for good, which is the one thing in this system that
     * cannot be undone, so it is deliberately narrow.
     *
     * It is a separate permission from deleting rather than the same one: an
     * HR Admin who may take a mistaken row off the masterlist has not thereby
     * been trusted to destroy it, and HR can withhold this one on its own.
     *
     * Only a record that is already soft deleted may go. The live masterlist
     * offers no route to it, so erasing is always the second of two decisions
     * taken at two different times, and an employee with leave on file is
     * refused here as well — see EmployeeService::eraseMany.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        return $user->can(Permission::EraseEmployees->value) && $employee->trashed();
    }

    /**
     * The tick-boxes on the deleted records list. As with deleteMany, asked
     * once for the batch; which rows may actually go is the service's call.
     */
    public function forceDeleteMany(User $user): bool
    {
        return $user->can(Permission::EraseEmployees->value);
    }

    /**
     * Gates the monthly rate column, for reading and for writing alike: a field
     * you are not allowed to see is not one you can be asked to fill in.
     * HR confirmed HR Admin only.
     */
    public function viewSalary(User $user): bool
    {
        return $user->can(Permission::ViewSalary->value);
    }

    /**
     * Everyone who can open an employee record reads the employment status, but
     * only an HR Admin types into it — the wording is copied from an appointment
     * paper, and HR wanted one pair of hands responsible for it.
     */
    public function setEmploymentStatus(User $user): bool
    {
        return $user->can(Permission::SetEmploymentStatus->value);
    }
}
