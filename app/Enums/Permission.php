<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The permission matrix from section 3 of docs/PROJECT_CONTEXT.md, in code.
 *
 * Permissions are stored as spatie/laravel-permission rows, but the list of
 * names lives here so the seeder, the policies and the props sent to React can
 * never drift apart. Adding a case and reseeding is the whole workflow.
 */
enum Permission: string
{
    case ViewTally = 'tally.view';
    case ManageLeave = 'leave.manage';
    case ManageEmployees = 'employees.manage';
    case DeactivateEmployees = 'employees.deactivate';
    case EraseEmployees = 'employees.erase';
    case ViewSalary = 'employees.view_salary';
    case SetEmploymentStatus = 'employees.set_employment_status';
    case GenerateExports = 'exports.generate';
    case ManageSettings = 'settings.manage';
    case ManageUsers = 'users.manage';
    case ViewActivityLog = 'activity_log.view';

    /**
     * Wording for the role management screen, phrased as HR would read it.
     */
    public function label(): string
    {
        return match ($this) {
            self::ViewTally => 'View yearly tally',
            self::ManageLeave => 'Add, edit and delete leave records',
            self::ManageEmployees => 'Add and edit employees',
            self::DeactivateEmployees => 'Deactivate employees',
            self::EraseEmployees => 'Erase a deleted employee record permanently',
            self::ViewSalary => 'View salary rate column',
            self::SetEmploymentStatus => 'Set an employee\'s employment status',
            self::GenerateExports => 'Print PDF and export Excel',
            self::ManageSettings => 'Manage leave types, offices and holidays',
            self::ManageUsers => 'Manage user accounts',
            self::ViewActivityLog => 'View activity log',
        };
    }

    /**
     * The camelCase key this permission is exposed under in `auth.can` on the
     * React side, so a page can hide what the user may not do.
     */
    public function frontendKey(): string
    {
        return match ($this) {
            self::ViewTally => 'viewTally',
            self::ManageLeave => 'manageLeave',
            self::ManageEmployees => 'manageEmployees',
            self::DeactivateEmployees => 'deactivateEmployees',
            self::EraseEmployees => 'eraseEmployees',
            self::ViewSalary => 'viewSalary',
            self::SetEmploymentStatus => 'setEmploymentStatus',
            self::GenerateExports => 'generateExports',
            self::ManageSettings => 'manageSettings',
            self::ManageUsers => 'manageUsers',
            self::ViewActivityLog => 'viewActivityLog',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
