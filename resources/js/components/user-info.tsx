import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import { type AuthUser } from '@/types';

/**
 * Accounts have no uploaded photos — initials come from the server so the
 * sidebar and the account menu never disagree about them.
 *
 * The same block sits on the sidebar and inside the white account menu, so it
 * takes the sidebar's colours only on the sidebar: the light-mode sidebar
 * tokens are near-white, which would vanish against the menu.
 */
export function UserInfo({ user, showEmail = false }: { user: AuthUser; showEmail?: boolean }) {
    const inMenu = showEmail;

    return (
        <>
            <Avatar className={cn('size-8 overflow-hidden rounded-lg ring-1 transition-all', inMenu ? 'ring-primary/20' : 'ring-sidebar-primary/20')}>
                <AvatarFallback
                    className={cn(
                        'rounded-lg bg-gradient-to-br text-xs font-bold',
                        inMenu ? 'from-primary/15 to-primary/5 text-primary' : 'from-sidebar-primary/25 to-sidebar-primary/10 text-sidebar-primary',
                    )}
                >
                    {user.initials}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-semibold">{user.name}</span>
                <span className={cn('truncate text-xs font-medium', inMenu ? 'text-muted-foreground' : 'text-sidebar-foreground/85')}>
                    {showEmail ? user.email : (user.roleLabel ?? 'No role assigned')}
                </span>
            </div>
        </>
    );
}
