import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type AuthUser } from '@/types';
import { Link } from '@inertiajs/react';
import { LogOut, SlidersHorizontal, UsersRound } from 'lucide-react';

interface UserMenuContentProps {
    user: AuthUser;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link className="block w-full cursor-pointer" href={route('profile.edit')} as="button" prefetch onClick={cleanup}>
                        <SlidersHorizontal className="mr-2" />
                        My settings
                    </Link>
                </DropdownMenuItem>
                {user.can.manageUsers && (
                    <DropdownMenuItem asChild>
                        <Link className="block w-full cursor-pointer" href={route('users.index')} as="button" prefetch onClick={cleanup}>
                            <UsersRound className="mr-2" />
                            User accounts
                        </Link>
                    </DropdownMenuItem>
                )}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className="block w-full cursor-pointer" method="post" href={route('logout')} as="button" onClick={cleanup}>
                    <LogOut className="mr-2" />
                    Sign out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
