import { EmployeeForm } from '@/components/employee-form';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type OfficeOption } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';

interface CreateEmployeeProps {
    offices: OfficeOption[];
    employmentStatuses: string[];
    can: { setEmploymentStatus: boolean; viewSalary: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Employees', href: '/employees' },
    { title: 'Add employee', href: '/employees/create' },
];

export default function CreateEmployee({ offices, employmentStatuses, can }: CreateEmployeeProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add employee" />

            <PageHeader
                eyebrow="Employees"
                title="Add employee"
                description="Everyone the office keeps leave records for. Once they are on the masterlist you can encode leave for them."
            />

            {/*
              Every employee belongs to an office, so with no offices yet the
              form could not be completed. Say so and point at the fix instead
              of showing a select with nothing in it.
            */}
            {offices.length === 0 ? (
                <section className="bg-card rounded-xl border">
                    <EmptyState
                        icon={Building2}
                        title="There are no offices yet"
                        description="Every employee reports to one office, so add the offices first. An HR Admin does this under Settings."
                        action={
                            <Button asChild>
                                <Link href={route('offices.index')}>Go to offices</Link>
                            </Button>
                        }
                    />
                </section>
            ) : (
                <EmployeeForm offices={offices} employmentStatuses={employmentStatuses} can={can} submitLabel="Add to masterlist" />
            )}
        </AppLayout>
    );
}
