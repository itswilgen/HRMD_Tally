import { StatusPill } from '@/components/account-pills';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { RoleSelector } from '@/components/role-selector';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { changeRoleBlockedReason, deactivateBlockedReason, deleteBlockedReason } from '@/lib/user-actions';
import { type BreadcrumbItem, type RoleOption, type UserAccount, type UserRole } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Loader2, Trash2, UserRoundCheck, UserRoundX } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

type EditAccountForm = {
    name: string;
    email: string;
    role: UserRole | '';
    password: string;
    password_confirmation: string;
};

interface EditUserProps {
    user: UserAccount;
    roles: RoleOption[];
    lastActiveAdminId: number | null;
    can: { changeRole: boolean; deactivate: boolean; delete: boolean };
}

export default function EditUser({ user, roles, lastActiveAdminId, can }: EditUserProps) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [processingAction, setProcessingAction] = useState(false);

    const { data, setData, patch, errors, processing } = useForm<EditAccountForm>({
        name: user.name,
        email: user.email,
        role: user.role ?? '',
        password: '',
        password_confirmation: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'User accounts', href: '/settings/users' },
        { title: user.name, href: route('users.edit', user.id) },
    ];

    /*
     * The policy decided whether each action is allowed; the helpers only turn
     * that refusal into the reason behind it. The fallbacks cover a rule added
     * to the policy but not yet mirrored here.
     */
    const roleLockedReason = can.changeRole ? null : (changeRoleBlockedReason(user, lastActiveAdminId) ?? 'This role cannot be changed.');
    const deactivateReason = can.deactivate ? null : (deactivateBlockedReason(user, lastActiveAdminId) ?? 'This account cannot be deactivated.');
    const deleteReason = can.delete ? null : (deleteBlockedReason(user, lastActiveAdminId) ?? 'This account cannot be deleted.');

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        patch(route('users.update', user.id));
    };

    const setActive = (active: boolean) => {
        const url = active ? route('users.activate', user.id) : route('users.deactivate', user.id);

        router.patch(url, {}, { preserveScroll: true });
    };

    const destroy = () => {
        router.delete(route('users.destroy', user.id), {
            onStart: () => setProcessingAction(true),
            onFinish: () => {
                setProcessingAction(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${user.name}`} />

            <PageHeader
                eyebrow="User accounts"
                title={user.name}
                description="Change what this person may do, or reset their password if they have been locked out."
                actions={<StatusPill isActive={user.isActive} />}
            />

            <form onSubmit={submit} className="bg-card max-w-2xl space-y-6 rounded-xl border p-5">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField id="name" label="Full name" error={errors.name}>
                        <Input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required autoComplete="name" />
                    </FormField>

                    <FormField id="email" label="Email address" hint="Used to sign in." error={errors.email}>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value.toLowerCase())}
                            required
                            autoComplete="off"
                        />
                    </FormField>
                </div>

                <RoleSelector
                    roles={roles}
                    value={data.role}
                    onChange={(role) => setData('role', role)}
                    error={errors.role}
                    lockedReason={roleLockedReason}
                />

                <div className="space-y-5 border-t pt-5">
                    <div>
                        <h2 className="text-sm font-medium">Reset password</h2>
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            Leave both fields empty to keep the current password. Filling them in replaces it immediately.
                        </p>
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField id="password" label="New password" optional hint="At least 8 characters." error={errors.password}>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                autoComplete="new-password"
                            />
                        </FormField>

                        <FormField id="password_confirmation" label="Repeat new password" optional error={errors.password_confirmation}>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(event) => setData('password_confirmation', event.target.value)}
                                autoComplete="new-password"
                            />
                        </FormField>
                    </div>
                </div>

                <div className="flex items-center gap-3 border-t pt-5">
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="animate-spin" aria-hidden="true" />}
                        Save changes
                    </Button>
                    <Button type="button" variant="ghost" asChild>
                        <Link href={route('users.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>

            <section className="max-w-2xl space-y-4 rounded-xl border p-5">
                <div>
                    <h2 className="text-sm font-medium">Sign-in and removal</h2>
                    <p className="text-muted-foreground mt-0.5 max-w-[65ch] text-xs">
                        Deactivating is the usual choice when someone leaves the office: they can no longer sign in, but everything they encoded keeps
                        their name on it.
                    </p>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                    {user.isActive ? (
                        <div className="space-y-1">
                            <Button type="button" variant="outline" onClick={() => setActive(false)} disabled={deactivateReason !== null}>
                                <UserRoundX aria-hidden="true" />
                                Deactivate account
                            </Button>
                            {deactivateReason && <p className="text-muted-foreground max-w-[40ch] text-xs">{deactivateReason}</p>}
                        </div>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => setActive(true)}>
                            <UserRoundCheck aria-hidden="true" />
                            Allow sign-in again
                        </Button>
                    )}

                    <div className="space-y-1">
                        <Button type="button" variant="destructive" onClick={() => setConfirmingDelete(true)} disabled={deleteReason !== null}>
                            <Trash2 aria-hidden="true" />
                            Delete account
                        </Button>
                        {deleteReason && <p className="text-muted-foreground max-w-[40ch] text-xs">{deleteReason}</p>}
                    </div>
                </div>
            </section>

            <ConfirmDialog
                open={confirmingDelete}
                onOpenChange={setConfirmingDelete}
                processing={processingAction}
                tone="destructive"
                title="Delete this account?"
                confirmLabel="Delete account"
                onConfirm={destroy}
                description={
                    <>
                        <p>
                            <span className="text-foreground font-medium">{user.name}</span> will be removed from the system. This cannot be undone.
                        </p>
                        <p>If this person may come back, deactivate the account instead.</p>
                    </>
                }
            />
        </AppLayout>
    );
}
