<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application roles, created as spatie/laravel-permission roles by RoleSeeder.
 *
 * Employees do not log in during Phase 1.
 */
enum UserRole: string
{
    case HrAdmin = 'hr_admin';
    case HrStaff = 'hr_staff';

    public function label(): string
    {
        return match ($this) {
            self::HrAdmin => 'HR Admin',
            self::HrStaff => 'HR Staff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HrAdmin => 'Full access, including user accounts, settings, salary rates and the activity log.',
            self::HrStaff => 'Encodes leave and maintains employee records. Cannot change settings or accounts.',
        };
    }

    /**
     * What each role may do, from section 3 of docs/PROJECT_CONTEXT.md.
     *
     * HR Staff deliberately cannot see the salary rate column: that was the one
     * "to confirm with HR" row in the matrix, and HR answered HR Admin only.
     *
     * Employment status is the same answer in a different place: HR Staff read
     * it on the employee record but cannot type into it, because it is copied
     * from an appointment paper only an HR Admin handles.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::HrAdmin => Permission::cases(),
            self::HrStaff => [
                Permission::ViewTally,
                Permission::ManageLeave,
                Permission::ManageEmployees,
                Permission::GenerateExports,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
