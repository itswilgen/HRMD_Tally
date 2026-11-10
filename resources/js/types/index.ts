import { LucideIcon } from 'lucide-react';

/**
 * Mirrors App\Enums\Permission::frontendKey(). Keep both in step: a key added
 * there and not here is simply unreachable from the interface.
 */
export type PermissionKey =
    | 'viewTally'
    | 'manageLeave'
    | 'manageEmployees'
    | 'deactivateEmployees'
    | 'eraseEmployees'
    | 'viewSalary'
    | 'setEmploymentStatus'
    | 'generateExports'
    | 'manageSettings'
    | 'manageUsers'
    | 'viewActivityLog';

export type PermissionMap = Record<PermissionKey, boolean>;

/** Mirrors App\Enums\UserRole. */
export type UserRole = 'hr_admin' | 'hr_staff';

/** App\Http\Resources\AuthenticatedUserResource — shared on every page. */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
    initials: string;
    emailVerifiedAt: string | null;
    role: UserRole | null;
    roleLabel: string | null;
    can: PermissionMap;
}

/** App\Http\Resources\UserResource — a row in the account management table. */
export interface UserAccount {
    id: number;
    name: string;
    email: string;
    role: UserRole | null;
    roleLabel: string | null;
    isActive: boolean;
    createdAt: string | null;
    isSelf: boolean;
    /** Leave rows this account encoded; above zero, it cannot be deleted. */
    encodedLeaveRecordsCount: number;
}

/**
 * App\Http\Resources\EmployeeResource — a row in the masterlist and the
 * record behind the edit form.
 */
export interface EmployeeRecord {
    id: number;
    employeeNo: string;
    /** The plantilla item this position is, when it holds one. */
    itemNo: string | null;
    lastName: string;
    firstName: string;
    middleName: string | null;
    suffix: string | null;
    /** "Dela Cruz, Juan P. Jr." — built server-side so every output matches. */
    listName: string;
    /** "Juan P. Dela Cruz Jr." */
    fullName: string;
    positionTitle: string;
    salaryGrade: number | null;
    /** Free text an HR Admin types; null until they do. */
    employmentStatus: string | null;
    officeId: number | null;
    officeName?: string;
    isActive: boolean;
    /** Set only on the deleted records list: when the row was deleted. */
    deletedAt: string | null;
    /** Present when the page asked for it; above zero the record has history. */
    leaveRecordsCount?: number;
    /**
     * Absent — not null — for anyone without the salary permission, so the
     * figure is not in the page source at all (RA 10173). decimal:2 arrives as
     * a string to keep the centavos exact.
     */
    monthlyRate?: string | null;
}

/** Mirrors App\Enums\DayPart. */
export type DayPartValue = 'WHOLE' | 'AM' | 'PM';

export interface DayPartOption {
    value: DayPartValue;
    label: string;
}

/** A tally column: every leave type, including ones HR has retired. */
export interface LeaveTypeColumn {
    id: number;
    code: string;
    name: string;
    /** Chip palette key resolved to tokens by LeaveChip, never a hex value. */
    color: string;
}

/** A leave type that may still be encoded against. */
export interface LeaveTypeOption {
    value: number;
    label: string;
    code: string;
    color: string;
}

/** One encoded day behind a chip. */
export interface TallyEntryDay {
    id: number;
    date: string;
    day: number;
    /** "23", or "13 PM" for a half day — written server-side. */
    dayText: string;
    dayPart: DayPartValue;
    remarks: string | null;
}

/** One leave type's days within one month, as one chip. */
export interface TallyEntry {
    leaveTypeId: number;
    code: string;
    color: string;
    /** "23–25", "3, 6–7", "13 PM". */
    dayFigures: string;
    /** The whole cell text, "23–25 MFL". The PDF and Excel write this string. */
    text: string;
    days: number;
    daysText: string;
    records: TallyEntryDay[];
}

export interface TallyMonth {
    month: number;
    entries: TallyEntry[];
    days: number;
    daysText: string;
}

/**
 * App\Services\LeaveTallyService — one employee's year. Every figure here is
 * built server-side so the screen, the PDF and the Excel export always match
 * (business rule 6); the frontend never adds a day up itself.
 */
export interface TallyRow {
    months: TallyMonth[];
    /** Keyed by leave type id. */
    totals: Record<string, number>;
    totalsText: Record<string, string>;
    grandTotal: number;
    grandTotalText: string;
}

/** App\Services\LeaveEncodingService::preview() — what a range would save. */
export interface LeavePreview {
    dates: { date: string; day: number; month: number; weekday: string }[];
    /** Weekends and holidays the range passes over, with the reason. */
    skipped: { date: string; day: number; reason: string }[];
    /** Dates this employee already has leave on that cannot share the day. */
    conflicts: { date: string; reason: string }[];
    days: number;
}

export interface OfficeOption {
    value: number;
    label: string;
}

/** A row on the offices settings screen. */
export interface OfficeRow {
    id: number;
    name: string;
    /** Including deactivated and removed records, which still hold the office. */
    employeesCount: number;
}

export interface RoleOption {
    value: UserRole;
    label: string;
    description: string;
}

export interface Auth {
    user: AuthUser | null;
}

export interface Flash {
    success: string | null;
    error: string | null;
    /** Changes on every request that carries a message, so repeats still show. */
    id: string | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Hidden unless the signed-in user holds this permission. */
    permission?: PermissionKey;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: Flash;
    [key: string]: unknown;
}

/** Shape of a Laravel resource collection wrapping a paginator. */
export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
}

/**
 * The starter kit's own components (profile, password, appearance) import this
 * name. It is the signed-in user, which is all those pages ever show.
 */
export type User = AuthUser;
