import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { RoleSelector } from '@/components/role-selector';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type RoleOption, type UserRole } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

/*
 * A type alias, not an interface: useForm's data must satisfy an index
 * signature, which only an alias gets implicitly.
 */
type CreateAccountForm = {
    name: string;
    email: string;
    role: UserRole | '';
    password: string;
    password_confirmation: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'User accounts', href: '/settings/users' },
    { title: 'Add account', href: '/settings/users/create' },
];

export default function CreateUser({ roles }: { roles: RoleOption[] }) {
    const { data, setData, post, errors, processing } = useForm<CreateAccountForm>({
        name: '',
        email: '',
        role: 'hr_staff',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('users.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add account" />

            <PageHeader
                eyebrow="User accounts"
                title="Add account"
                description="Create a sign-in for someone in the HRMD. Give them the password in person or by phone, and ask them to change it from My settings."
            />

            <form onSubmit={submit} className="bg-card max-w-2xl space-y-6 rounded-xl border p-5">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField id="name" label="Full name" error={errors.name}>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            required
                            autoFocus
                            autoComplete="name"
                            placeholder="Maria Santos"
                        />
                    </FormField>

                    <FormField id="email" label="Email address" hint="Used to sign in." error={errors.email}>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value.toLowerCase())}
                            required
                            autoComplete="off"
                            placeholder="maria.santos@hrmd.gov.ph"
                        />
                    </FormField>
                </div>

                <RoleSelector roles={roles} value={data.role} onChange={(role) => setData('role', role)} error={errors.role} />

                <div className="grid gap-5 border-t pt-5 sm:grid-cols-2">
                    <FormField id="password" label="Temporary password" hint="At least 8 characters." error={errors.password}>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </FormField>

                    <FormField id="password_confirmation" label="Repeat password" error={errors.password_confirmation}>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </FormField>
                </div>

                <div className="flex items-center gap-3 border-t pt-5">
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="animate-spin" aria-hidden="true" />}
                        Create account
                    </Button>
                    <Button type="button" variant="ghost" asChild>
                        <Link href={route('users.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
