import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { type AuthUser } from '@/types';

/**
 * Accounts have no uploaded photos — initials come from the server so the
 * sidebar and the account menu never disagree about them.
 */
export function UserInfo({ user, showEmail = false }: { user: AuthUser; showEmail?: boolean }) {
    return (
        <>
            <Avatar className="size-8 overflow-hidden rounded-md">
                <AvatarFallback className="bg-sidebar-primary/15 text-sidebar-primary rounded-md text-xs font-semibold">
                    {user.initials}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-medium">{user.name}</span>
                {/* Inherits its colour so this reads correctly both on the ink
                    sidebar and inside the light account menu. */}
                <span className="truncate text-xs opacity-70">{showEmail ? user.email : (user.roleLabel ?? 'No role assigned')}</span>
            </div>
        </>
    );
}
