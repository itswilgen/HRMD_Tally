import { type AuthUser, type PermissionKey, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The signed-in user, for anything rendered inside the authenticated shell.
 *
 * `auth.user` is nullable because the sign-in screens share the same props. A
 * null here means this component was mounted outside the `auth` middleware,
 * which is a wiring mistake rather than a state to render around.
 */
export function useAuthUser(): AuthUser {
    const { auth } = usePage<SharedData>().props;

    if (!auth.user) {
        throw new Error('useAuthUser() was called on a page that has no signed-in user.');
    }

    return auth.user;
}

/**
 * Permission check for hiding what the user may not do. The server's policies
 * are what actually refuse the action — this only keeps dead buttons off the
 * screen.
 */
export function useCan(): (permission: PermissionKey) => boolean {
    const user = useAuthUser();

    return (permission) => user.can[permission] === true;
}
