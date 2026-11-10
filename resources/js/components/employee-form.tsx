import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type EmployeeRecord, type OfficeOption } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { Info, Loader2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

/*
 * A type alias, not an interface: useForm's data must satisfy an index
 * signature, which only an alias gets implicitly.
 */
type EmployeeFormData = {
    employee_no: string;
    item_no: string;
    last_name: string;
    first_name: string;
    middle_name: string;
    suffix: string;
    position_title: string;
    salary_grade: string;
    office_id: string;
    employment_status: string;
    monthly_rate: string;
};

interface EmployeeFormProps {
    /** The record being edited, or undefined when adding someone new. */
    employee?: EmployeeRecord;
    offices: OfficeOption[];
    /** Employment statuses already in use, most used first. Empty for HR Staff. */
    employmentStatuses: string[];
    can: { setEmploymentStatus: boolean; viewSalary: boolean };
    submitLabel: string;
}

export function EmployeeForm({ employee, offices, employmentStatuses, can, submitLabel }: EmployeeFormProps) {
    const isEditing = employee !== undefined;

    const { data, setData, post, patch, transform, errors, processing } = useForm<EmployeeFormData>({
        employee_no: employee?.employeeNo ?? '',
        item_no: employee?.itemNo ?? '',
        last_name: employee?.lastName ?? '',
        first_name: employee?.firstName ?? '',
        middle_name: employee?.middleName ?? '',
        suffix: employee?.suffix ?? '',
        position_title: employee?.positionTitle ?? '',
        salary_grade: employee?.salaryGrade === null || employee?.salaryGrade === undefined ? '' : String(employee.salaryGrade),
        office_id: employee === undefined ? (offices.length === 1 ? String(offices[0].value) : '') : String(employee.officeId),
        employment_status: employee?.employmentStatus ?? '',
        monthly_rate: employee?.monthlyRate ?? '',
    });

    /*
     * Two of these fields are privilege-gated, and the request drops them for
     * anyone not allowed to set them. They are left out of the payload here
     * too, so HR Staff saving a record never posts a value for a control their
     * form did not show them.
     */
    transform((payload) => {
        const { employment_status, monthly_rate, ...rest } = payload;

        return {
            ...rest,
            ...(can.setEmploymentStatus ? { employment_status } : {}),
            ...(can.viewSalary ? { monthly_rate } : {}),
        };
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEditing) {
            patch(route('employees.update', employee.id));

            return;
        }

        post(route('employees.store'));
    };

    return (
        <form onSubmit={submit} className="bg-card w-full rounded-xl border">
            <Section
                title="Name"
                description="Typed the way it appears on the appointment paper. The tally sheet prints the surname first and only the middle initial."
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField id="last_name" label="Surname" error={errors.last_name}>
                        <Input
                            id="last_name"
                            value={data.last_name}
                            onChange={(event) => setData('last_name', event.target.value)}
                            required
                            autoFocus
                            autoComplete="off"
                            placeholder="Dela Cruz"
                        />
                    </FormField>

                    <FormField id="first_name" label="First name" error={errors.first_name}>
                        <Input
                            id="first_name"
                            value={data.first_name}
                            onChange={(event) => setData('first_name', event.target.value)}
                            required
                            autoComplete="off"
                            placeholder="Juan"
                        />
                    </FormField>

                    <FormField
                        id="middle_name"
                        label="Middle name"
                        hint="Only the initial is printed."
                        optional
                        error={errors.middle_name}
                        className="sm:col-span-1"
                    >
                        <Input
                            id="middle_name"
                            value={data.middle_name}
                            onChange={(event) => setData('middle_name', event.target.value)}
                            autoComplete="off"
                            placeholder="Pascual"
                        />
                    </FormField>

                    <FormField id="suffix" label="Suffix" hint="Jr., Sr., III." optional error={errors.suffix}>
                        <Input id="suffix" value={data.suffix} onChange={(event) => setData('suffix', event.target.value)} autoComplete="off" />
                    </FormField>
                </div>
            </Section>

            <Section title="Appointment" description="Where this employee works and what they are appointed as.">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField id="employee_no" label="Employee number" error={errors.employee_no}>
                        <Input
                            id="employee_no"
                            value={data.employee_no}
                            onChange={(event) => setData('employee_no', event.target.value)}
                            required
                            autoComplete="off"
                            placeholder="2026-00123"
                            data-numeric
                        />
                    </FormField>

                    <FormField
                        id="item_no"
                        label="Item number"
                        hint="The plantilla item, as HR's masterlist writes it."
                        optional
                        error={errors.item_no}
                    >
                        <Input
                            id="item_no"
                            value={data.item_no}
                            onChange={(event) => setData('item_no', event.target.value)}
                            autoComplete="off"
                            placeholder="1"
                            data-numeric
                        />
                    </FormField>

                    <FormField id="office_id" label="Office" error={errors.office_id} className="sm:col-span-2">
                        <Select value={data.office_id} onValueChange={(value) => setData('office_id', value)}>
                            <SelectTrigger id="office_id" className="cursor-pointer">
                                <SelectValue placeholder="Choose an office" />
                            </SelectTrigger>
                            <SelectContent>
                                {offices.map((office) => (
                                    <SelectItem key={office.value} value={String(office.value)}>
                                        {office.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>

                    <FormField id="position_title" label="Position title" error={errors.position_title} className="sm:col-span-2">
                        <Input
                            id="position_title"
                            value={data.position_title}
                            onChange={(event) => setData('position_title', event.target.value)}
                            required
                            autoComplete="off"
                            placeholder="Administrative Aide IV"
                        />
                    </FormField>

                    <FormField id="salary_grade" label="Salary grade" hint="1 to 33. Blank for job order." optional error={errors.salary_grade}>
                        <Input
                            id="salary_grade"
                            type="number"
                            min={1}
                            max={33}
                            step={1}
                            value={data.salary_grade}
                            onChange={(event) => setData('salary_grade', event.target.value)}
                            autoComplete="off"
                            data-numeric
                        />
                    </FormField>

                    {can.setEmploymentStatus ? (
                        <FormField
                            id="employment_status"
                            label="Employment status"
                            hint="Type it as the appointment paper words it."
                            optional
                            error={errors.employment_status}
                        >
                            <Input
                                id="employment_status"
                                value={data.employment_status}
                                onChange={(event) => setData('employment_status', event.target.value)}
                                autoComplete="off"
                                list="employment-status-suggestions"
                                maxLength={60}
                                placeholder="Permanent"
                            />
                            {/*
                              A native datalist: HR gets what colleagues have
                              already typed, so the spelling stays consistent,
                              while still being free to type something new.
                            */}
                            <datalist id="employment-status-suggestions">
                                {employmentStatuses.map((status) => (
                                    <option key={status} value={status} />
                                ))}
                            </datalist>
                        </FormField>
                    ) : (
                        <ReadOnlyField
                            label="Employment status"
                            value={employee?.employmentStatus ?? null}
                            blankText="Not filled in yet"
                            note="An HR Admin fills this in from the appointment paper."
                        />
                    )}
                </div>
            </Section>

            {can.viewSalary && (
                <Section
                    title="Pay"
                    description="Personal information under the Data Privacy Act. Only an HR Admin can see or change this, and the change is recorded in the activity log."
                >
                    {/* The same two columns as the sections above, so the rate lines up under salary grade. */}
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField id="monthly_rate" label="Monthly rate" hint="Pesos, e.g. 23450.00" optional error={errors.monthly_rate}>
                            <Input
                                id="monthly_rate"
                                type="number"
                                min={0}
                                step="0.01"
                                value={data.monthly_rate}
                                onChange={(event) => setData('monthly_rate', event.target.value)}
                                autoComplete="off"
                                data-numeric
                            />
                        </FormField>
                    </div>
                </Section>
            )}

            <div className="flex items-center gap-3 p-5">
                <Button type="submit" disabled={processing}>
                    {processing && <Loader2 className="animate-spin" aria-hidden="true" />}
                    {submitLabel}
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <Link href={route('employees.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}

/**
 * One group of fields with its own heading, ruled off like a block on the paper
 * form. The heading column sits beside the fields on a wide screen and above
 * them on a narrow one.
 */
function Section({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
    return (
        <section className="grid gap-5 border-b p-5 last:border-0 lg:grid-cols-[14rem_1fr] lg:gap-8">
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-muted-foreground max-w-[42ch] text-xs">{description}</p>
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}

/**
 * A field the signed-in user may read but not change. Shown as text with the
 * reason, rather than as a disabled input: a greyed-out box invites clicking
 * and says nothing about why it will not respond.
 */
function ReadOnlyField({ label, value, blankText, note }: { label: string; value: string | null; blankText: string; note: string }) {
    return (
        <div className="grid gap-1.5">
            <span className="text-sm font-medium">{label}</span>
            <p className={value === null ? 'text-muted-foreground text-sm italic' : 'text-sm'}>{value ?? blankText}</p>
            <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                <Info aria-hidden="true" className="mt-px size-3.5 shrink-0" />
                {note}
            </p>
        </div>
    );
}
