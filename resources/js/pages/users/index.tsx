import { RolePill, StatusPill } from '@/components/account-pills';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataPagination } from '@/components/data-pagination';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { deactivateBlockedReason, deleteBlockedReason } from '@/lib/user-actions';
import { type BreadcrumbItem, type Paginated, type RoleOption, type UserAccount } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Ellipsis, Pencil, Search, Trash2, UserPlus, UserRoundCheck, UserRoundX, UsersRound, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

interface UsersIndexProps {
    users: Paginated<UserAccount>;
    roles: RoleOption[];
    filters: { search: string; role: string; status: string };
    lastActiveAdminId: number | null;
}

type PendingAction = { kind: 'deactivate' | 'delete'; user: UserAccount };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'User accounts', href: '/settings/users' }];

const ALL = 'all';
const SEARCH_DEBOUNCE_MS = 300;

export default function UsersIndex({ users, roles, filters, lastActiveAdminId }: UsersIndexProps) {
    const reduceMotion = useReducedMotion();
    const [search, setSearch] = useState(filters.search);
    const [pending, setPending] = useState<PendingAction | null>(null);
    const [processing, setProcessing] = useState(false);

    const hasFilters = filters.search !== '' || filters.role !== '' || filters.status !== '';

    const applyFilters = (changes: Partial<UsersIndexProps['filters']>) => {
        const next = { ...filters, ...changes };

        router.get(
            route('users.index'),
            {
                search: next.search || undefined,
                role: next.role || undefined,
                status: next.status || undefined,
            },
            // replace, so typing in the search box does not fill the browser
            // history with one entry per keystroke.
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Typed search is debounced: HR searches by surname, which means a lot of
    // keystrokes against a table of a thousand accounts.
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        if (search === filters.search) {
            return;
        }

        const timer = window.setTimeout(() => applyFilters({ search }), SEARCH_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const clearFilters = () => {
        setSearch('');
        router.get(route('users.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    const setActive = (user: UserAccount, active: boolean) => {
        const url = active ? route('users.activate', user.id) : route('users.deactivate', user.id);

        router.patch(url, {}, { preserveScroll: true });
    };

    const confirmPending = () => {
        if (!pending) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setPending(null);
            },
        };

        if (pending.kind === 'deactivate') {
            router.patch(route('users.deactivate', pending.user.id), {}, options);

            return;
        }

        router.delete(route('users.destroy', pending.user.id), options);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User accounts" />

            <PageHeader
                eyebrow="Administration"
                title="User accounts"
                description="Who can sign in to the tally sheet, and what each of them is allowed to do. Staff who leave the office should be deactivated, not deleted — their encoding history stays attached to the records."
                actions={
                    <Button asChild>
                        <Link href={route('users.create')}>
                            <UserPlus aria-hidden="true" />
                            Add account
                        </Link>
                    </Button>
                }
            />

            <section className="border-border/80 bg-card overflow-hidden rounded-xl border shadow-xs">
                <div className="flex flex-col gap-3.5 border-b p-4 lg:flex-row lg:items-end">
                    <div className="grid flex-1 gap-1.5">
                        <Label htmlFor="search" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Search
                        </Label>
                        <div className="relative">
                            <Search
                                aria-hidden="true"
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2"
                            />
                            <Input
                                id="search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Name or email address"
                                className="h-11 rounded-lg pl-10"
                                autoComplete="off"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5 lg:w-48">
                        <Label htmlFor="role" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Role
                        </Label>
                        <Select value={filters.role || ALL} onValueChange={(value) => applyFilters({ role: value === ALL ? '' : value })}>
                            <SelectTrigger id="role" className="h-11 cursor-pointer rounded-lg">
                                <SelectValue placeholder="All roles" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All roles</SelectItem>
                                {roles.map((role) => (
                                    <SelectItem key={role.value} value={role.value}>
                                        {role.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5 lg:w-44">
                        <Label htmlFor="status" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Status
                        </Label>
                        <Select value={filters.status || ALL} onValueChange={(value) => applyFilters({ status: value === ALL ? '' : value })}>
                            <SelectTrigger id="status" className="h-11 cursor-pointer rounded-lg">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Deactivated</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters} className="h-11 cursor-pointer rounded-lg lg:mb-px">
                            <X aria-hidden="true" />
                            Clear
                        </Button>
                    )}
                </div>

                {users.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState
                            icon={Search}
                            title="No accounts match this search"
                            description="Check the spelling of the name, or clear the filters to see everyone."
                            action={
                                <Button type="button" variant="outline" onClick={clearFilters} className="cursor-pointer">
                                    Clear filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={UsersRound}
                            title="No accounts yet"
                            description="Add an account for each person in the HRMD who encodes leave or reads the tally sheet."
                            action={
                                <Button asChild>
                                    <Link href={route('users.create')}>
                                        <UserPlus aria-hidden="true" />
                                        Add account
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Account</TableHead>
                                <TableHead className="hidden sm:table-cell">Role</TableHead>
                                <TableHead className="hidden sm:table-cell">Status</TableHead>
                                <TableHead className="hidden lg:table-cell">Added</TableHead>
                                <TableHead className="w-12 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.map((user, index) => {
                                const deactivateReason = deactivateBlockedReason(user, lastActiveAdminId);
                                const deleteReason = deleteBlockedReason(user, lastActiveAdminId);

                                return (
                                    <motion.tr
                                        key={user.id}
                                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={
                                            reduceMotion
                                                ? { duration: 0 }
                                                : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: [0.22, 1, 0.36, 1] }
                                        }
                                        className="hover:bg-accent/40 border-b transition-colors last:border-0"
                                    >
                                        <TableCell className="py-2">
                                            <div className="flex items-center gap-3">
                                                <span
                                                    aria-hidden="true"
                                                    className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-md text-xs font-semibold"
                                                >
                                                    {initialsOf(user.name)}
                                                </span>
                                                {/*
                                                  The max-width is what lets the
                                                  row fit a 375px screen: an
                                                  email address does not wrap,
                                                  so without a ceiling it sets
                                                  the column's minimum width.
                                                */}
                                                <div className="max-w-[46vw] min-w-0 sm:max-w-none">
                                                    <div className="flex items-center gap-2">
                                                        <span className="truncate font-medium">{user.name}</span>
                                                        {user.isSelf && <span className="text-muted-foreground text-2xs">(you)</span>}
                                                    </div>
                                                    <a
                                                        href={`mailto:${user.email}`}
                                                        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring block truncate rounded-sm text-xs underline-offset-4 transition-colors hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                                    >
                                                        {user.email}
                                                    </a>
                                                    {/*
                                                      Role and status move under
                                                      the name on a phone: as
                                                      columns of their own they
                                                      took more width than the
                                                      name they describe.
                                                    */}
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 sm:hidden">
                                                        <RolePill role={user.role} label={user.roleLabel} />
                                                        <StatusPill isActive={user.isActive} />
                                                    </div>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <RolePill role={user.role} label={user.roleLabel} />
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <StatusPill isActive={user.isActive} />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground hidden lg:table-cell">
                                            {user.createdAt ? <time dateTime={user.createdAt}>{formatDate(user.createdAt)}</time> : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="size-8 cursor-pointer">
                                                        <Ellipsis aria-hidden="true" />
                                                        <span className="sr-only">Actions for {user.name}</span>
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-64">
                                                    <DropdownMenuItem asChild>
                                                        <Link href={route('users.edit', user.id)} className="cursor-pointer">
                                                            <Pencil aria-hidden="true" />
                                                            Edit account
                                                        </Link>
                                                    </DropdownMenuItem>

                                                    {user.isActive ? (
                                                        <DropdownMenuItem
                                                            disabled={deactivateReason !== null}
                                                            onSelect={() => setPending({ kind: 'deactivate', user })}
                                                            className="cursor-pointer"
                                                        >
                                                            <UserRoundX aria-hidden="true" />
                                                            <span className="flex flex-col items-start">
                                                                Deactivate
                                                                {deactivateReason && (
                                                                    <span className="text-muted-foreground text-2xs whitespace-normal">
                                                                        {deactivateReason}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem onSelect={() => setActive(user, true)} className="cursor-pointer">
                                                            <UserRoundCheck aria-hidden="true" />
                                                            Allow sign-in again
                                                        </DropdownMenuItem>
                                                    )}

                                                    <DropdownMenuSeparator />

                                                    <DropdownMenuItem
                                                        disabled={deleteReason !== null}
                                                        onSelect={() => setPending({ kind: 'delete', user })}
                                                        className="text-destructive focus:text-destructive cursor-pointer"
                                                    >
                                                        <Trash2 aria-hidden="true" />
                                                        <span className="flex flex-col items-start">
                                                            Delete account
                                                            {deleteReason && (
                                                                <span className="text-muted-foreground text-2xs whitespace-normal">
                                                                    {deleteReason}
                                                                </span>
                                                            )}
                                                        </span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </motion.tr>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}

                <DataPagination meta={users.meta} links={users.links} label="accounts" />
            </section>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                processing={processing}
                tone={pending?.kind === 'delete' ? 'destructive' : 'default'}
                title={pending?.kind === 'delete' ? 'Delete this account?' : 'Deactivate this account?'}
                confirmLabel={pending?.kind === 'delete' ? 'Delete account' : 'Deactivate account'}
                onConfirm={confirmPending}
                description={
                    pending?.kind === 'delete' ? (
                        <>
                            <p>
                                <span className="text-foreground font-medium">{pending.user.name}</span> will be removed from the system. This cannot
                                be undone.
                            </p>
                            <p>If this person may come back, deactivate the account instead.</p>
                        </>
                    ) : (
                        <p>
                            <span className="text-foreground font-medium">{pending?.user.name}</span> will no longer be able to sign in. Nothing they
                            have encoded is changed, and you can allow sign-in again at any time.
                        </p>
                    )
                }
            />
        </AppLayout>
    );
}

function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    const first = parts[0].charAt(0);
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';

    return `${first}${last}`.toUpperCase();
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}
