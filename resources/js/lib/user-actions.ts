import { type UserAccount } from '@/types';

/**
 * Why an account action is unavailable, phrased for the person clicking.
 *
 * These mirror App\Policies\UserPolicy, which is what actually refuses the
 * request. They exist so a button can be disabled with a reason instead of
 * returning a bare 403 after the click. If a rule changes in the policy, it
 * changes here too.
 */
export function deactivateBlockedReason(user: UserAccount, lastActiveAdminId: number | null): string | null {
    if (user.isSelf) {
        return 'You cannot deactivate your own account.';
    }

    if (user.id === lastActiveAdminId) {
        return 'This is the only active HR Admin. Add another one first.';
    }

    return null;
}

export function changeRoleBlockedReason(user: UserAccount, lastActiveAdminId: number | null): string | null {
    if (user.isSelf) {
        return 'You cannot change your own role.';
    }

    if (user.id === lastActiveAdminId) {
        return 'This is the only active HR Admin. Add another one first.';
    }

    return null;
}

export function deleteBlockedReason(user: UserAccount, lastActiveAdminId: number | null): string | null {
    if (user.isSelf) {
        return 'You cannot delete your own account.';
    }

    if (user.id === lastActiveAdminId) {
        return 'This is the only active HR Admin. Add another one first.';
    }

    if (user.encodedLeaveRecordsCount > 0) {
        const records = user.encodedLeaveRecordsCount === 1 ? '1 leave record' : `${user.encodedLeaveRecordsCount} leave records`;

        return `This account encoded ${records}. Deactivate it instead, so the records keep their history.`;
    }

    return null;
}
