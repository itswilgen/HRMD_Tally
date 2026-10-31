import { Badge } from '@/components/ui/badge';
import { type UserRole } from '@/types';

/**
 * Whether the account can sign in. Green means in use; a deactivated account
 * is deliberately quiet rather than alarming — it is a normal state for a
 * staff member who has moved office.
 */
export function StatusPill({ isActive }: { isActive: boolean }) {
    return (
        <Badge variant={isActive ? 'success' : 'muted'}>
            <span aria-hidden="true" className={isActive ? 'bg-success size-1.5 rounded-full' : 'bg-muted-foreground/60 size-1.5 rounded-full'} />
            {isActive ? 'Active' : 'Deactivated'}
        </Badge>
    );
}

export function RolePill({ role, label }: { role: UserRole | null; label: string | null }) {
    if (!role) {
        return <Badge variant="warning">No role assigned</Badge>;
    }

    // HR Admin is the one role that can change permissions, so it is the one
    // that gets the stronger treatment.
    return <Badge variant={role === 'hr_admin' ? 'default' : 'secondary'}>{label ?? role}</Badge>;
}
