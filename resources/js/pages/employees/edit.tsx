import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmployeeForm } from '@/components/employee-form';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type EmployeeRecord, type OfficeOption } from '@/types';
import { Head, router } from '@inertiajs/react';
import { UserRoundCheck, UserRoundX } from 'lucide-react';
import { useState } from 'react';

interface EditEmployeeProps {
    employee: EmployeeRecord;
    offices: OfficeOption[];
    employmentStatuses: string[];
    can: { setEmploymentStatus: boolean; viewSalary: boolean; deactivate: boolean };
}

export default function EditEmployee({ employee, offices, employmentStatuses, can }: EditEmployeeProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/employees' },
        { title: employee.listName, href: route('employees.edit', employee.id) },
    ];

    const setActive = (active: boolean) => {
        router.patch(
            route(active ? 'employees.activate' : 'employees.deactivate', employee.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );
    };

    const recordCount = employee.leaveRecordsCount ?? 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={employee.listName} />

            <PageHeader
                eyebrow="Employees"
                title={employee.fullName}
                description={
                    recordCount === 0
                        ? 'No leave has been encoded for this employee yet.'
                        : `${recordCount} leave ${recordCount === 1 ? 'day' : 'days'} encoded so far. Changing a name or office here does not change any of them.`
                }
                actions={
                    can.deactivate ? (
                        employee.isActive ? (
                            <Button type="button" variant="outline" onClick={() => setConfirming(true)} className="cursor-pointer">
                                <UserRoundX aria-hidden="true" />
                                Deactivate
                            </Button>
                        ) : (
                            <Button type="button" variant="outline" onClick={() => setActive(true)} className="cursor-pointer">
                                <UserRoundCheck aria-hidden="true" />
                                Put back on the list
                            </Button>
                        )
                    ) : undefined
                }
            />

            {!employee.isActive && (
                <div className="flex items-center gap-2">
                    <Badge variant="muted">Deactivated</Badge>
                    <p className="text-muted-foreground text-sm">
                        This employee does not appear when encoding leave. Their past records are untouched.
                    </p>
                </div>
            )}

            <EmployeeForm employee={employee} offices={offices} employmentStatuses={employmentStatuses} can={can} submitLabel="Save changes" />

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                processing={processing}
                title="Take this employee off the list?"
                confirmLabel="Deactivate"
                onConfirm={() => setActive(false)}
                description={
                    <>
                        <p>
                            <span className="text-foreground font-medium">{employee.fullName}</span> will no longer appear when encoding leave or on
                            this year&apos;s tally sheet.
                        </p>
                        <p>
                            {recordCount === 0
                                ? 'Nothing is deleted, and you can put them back on the list at any time.'
                                : `The ${recordCount} leave ${recordCount === 1 ? 'record' : 'records'} already encoded for them stay exactly as they are.`}
                        </p>
                    </>
                }
            />
        </AppLayout>
    );
}
