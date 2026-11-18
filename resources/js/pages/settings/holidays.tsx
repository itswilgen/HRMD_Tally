import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type HolidayKindOption, type HolidayKindValue, type HolidayRow } from '@/types';
import { Head, type InertiaFormProps, Link, router, useForm } from '@inertiajs/react';
import { CalendarOff, Check, ChevronLeft, ChevronRight, Loader2, Pencil, Plus, Repeat, Trash2, TriangleAlert, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { type FormEventHandler, useState } from 'react';

interface HolidaysPageProps {
    year: number;
    currentYear: number;
    yearRange: { first: number; last: number };
    holidays: HolidayRow[];
    kinds: HolidayKindOption[];
    can: { manage: boolean };
}

// A type alias, not an interface: Inertia's form data needs an index signature.
type HolidayForm = {
    date: string;
    name: string;
    kind: HolidayKindValue | '';
    confirm_encoded: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Holidays', href: '/settings/holidays' }];

const EASE = [0.22, 1, 0.36, 1] as const;

/** What each kind means for the person entering it, in HR's words. */
const KIND_HINTS: Record<HolidayKindValue, string> = {
    REGULAR_FIXED: 'Same date every year, like Christmas. Enter it once.',
    REGULAR_MOVABLE: 'Changes every year: Holy Week, Eid, National Heroes Day. Enter it each year.',
    SPECIAL_NON_WORKING: 'Set each year by proclamation. Enter it each year.',
};

/** Y-m-d read as a calendar day, never through UTC, so it cannot slip a day. */
function parseDay(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString()} ${count === 1 ? one : many}`;
}

/**
 * Date, name and kind, shared by the add form and the inline edit. The
 * confirmation box appears only when the server says leave is already on the
 * day, and unticks itself whenever the date or kind changes.
 */
function HolidayFields({
    form,
    kinds,
    idPrefix,
    recurringNote = false,
}: {
    form: InertiaFormProps<HolidayForm>;
    kinds: HolidayKindOption[];
    idPrefix: string;
    recurringNote?: boolean;
}) {
    const { data, setData, errors } = form;

    const changeDay = (patch: Partial<HolidayForm>) => {
        setData((current) => ({ ...current, ...patch, confirm_encoded: false }));
        form.clearErrors('confirm_encoded');
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-[10.5rem_1fr_15rem]">
                <div className="grid content-start gap-1.5">
                    <Label htmlFor={`${idPrefix}-date`}>Date</Label>
                    <Input
                        id={`${idPrefix}-date`}
                        type="date"
                        min="2000-01-01"
                        max="2100-12-31"
                        value={data.date}
                        onChange={(event) => changeDay({ date: event.target.value })}
                        className="cursor-pointer"
                        required
                    />
                    <InputError message={errors.date} />
                </div>
                <div className="grid content-start gap-1.5">
                    <Label htmlFor={`${idPrefix}-name`}>Name</Label>
                    <Input
                        id={`${idPrefix}-name`}
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        placeholder="Eid'l Fitr"
                        autoComplete="off"
                        maxLength={150}
                        required
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid content-start gap-1.5">
                    <Label htmlFor={`${idPrefix}-kind`}>Kind</Label>
                    <Select value={data.kind} onValueChange={(value) => changeDay({ kind: value as HolidayKindValue })}>
                        <SelectTrigger id={`${idPrefix}-kind`}>
                            <SelectValue placeholder="Pick a kind" />
                        </SelectTrigger>
                        <SelectContent>
                            {kinds.map((kind) => (
                                <SelectItem key={kind.value} value={kind.value}>
                                    {kind.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.kind} />
                </div>
            </div>

            {data.kind !== '' && (
                <p className="text-muted-foreground -mt-1 text-xs">
                    {KIND_HINTS[data.kind]}
                    {recurringNote && data.kind === 'REGULAR_FIXED' && ' Changing the day here changes it in every year.'}
                </p>
            )}

            {errors.confirm_encoded && (
                <div className="border-warning/30 bg-warning-surface text-warning grid gap-3 rounded-lg border p-3 text-sm" role="alert">
                    <p className="flex gap-2">
                        <TriangleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                        <span>{errors.confirm_encoded}</span>
                    </p>
                    <label htmlFor={`${idPrefix}-confirm`} className="text-foreground flex cursor-pointer items-center gap-2 pl-6 font-medium">
                        <Checkbox
                            id={`${idPrefix}-confirm`}
                            checked={data.confirm_encoded}
                            onCheckedChange={(checked) => setData('confirm_encoded', checked === true)}
                        />
                        Save anyway. I will check those records on the tally.
                    </label>
                </div>
            )}
        </div>
    );
}

/** The calendar leaf: month, day, weekday. The one detail this page is built around. */
function DayLeaf({ date, muted = false }: { date: string; muted?: boolean }) {
    const day = parseDay(date);

    return (
        <time
            dateTime={date}
            className={cn(
                'bg-background flex w-14 shrink-0 flex-col items-center overflow-hidden rounded-md border text-center leading-none',
                muted && 'opacity-60',
            )}
        >
            <span className="bg-primary text-primary-foreground w-full py-1 text-[0.625rem] font-semibold tracking-wider uppercase">
                {day.toLocaleDateString('en-PH', { month: 'short' })}
            </span>
            <span className="font-display pt-1.5 text-xl font-semibold" data-numeric>
                {day.getDate()}
            </span>
            <span className="text-muted-foreground pt-0.5 pb-1.5 text-[0.625rem] font-medium uppercase">
                {day.toLocaleDateString('en-PH', { weekday: 'short' })}
            </span>
        </time>
    );
}

export default function Holidays({ year, currentYear, yearRange, holidays, kinds, can }: HolidaysPageProps) {
    const reduceMotion = useReducedMotion();
    const [editing, setEditing] = useState<HolidayRow | null>(null);
    const [removing, setRemoving] = useState<HolidayRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addForm = useForm<HolidayForm>({ date: '', name: '', kind: '', confirm_encoded: false });
    const editForm = useForm<HolidayForm>({ date: '', name: '', kind: '', confirm_encoded: false });

    const recurring = holidays.filter((holiday) => holiday.recursYearly).length;
    const forThisYear = holidays.length - recurring;
    const weekdays = holidays.filter((holiday) => {
        const weekday = parseDay(holiday.date).getDay();

        return weekday !== 0 && weekday !== 6;
    }).length;

    const add: FormEventHandler = (event) => {
        event.preventDefault();

        const addedYear = Number(addForm.data.date.slice(0, 4));

        addForm.post(route('holidays.store'), {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();

                // A holiday added for another year would vanish from this
                // list; show the year it went into instead.
                if (addedYear !== year) {
                    router.get(route('holidays.index'), { year: addedYear }, { preserveScroll: true });
                }
            },
        });
    };

    const startEditing = (holiday: HolidayRow) => {
        setEditing(holiday);
        editForm.setData({ date: holiday.date, name: holiday.name, kind: holiday.kind, confirm_encoded: false });
        editForm.clearErrors();
    };

    const saveEdit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!editing) {
            return;
        }

        editForm.patch(route('holidays.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const confirmRemove = () => {
        if (!removing) {
            return;
        }

        router.delete(route('holidays.destroy', removing.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    };

    const yearLink = (target: number) => route('holidays.index', { year: target });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Holidays ${year}`} />

            <PageHeader
                eyebrow="Administration"
                title="Holidays"
                description="Days the office is closed. Leave encoding skips them, along with Saturdays and Sundays, so a range never counts a holiday as leave."
            />

            {/* Year switcher: one year at a time, as HR plans them. */}
            <nav aria-label="Year" className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="bg-card flex items-center rounded-lg border p-1">
                    <Button asChild={year > yearRange.first} variant="ghost" size="icon" className="size-9" disabled={year <= yearRange.first}>
                        {year > yearRange.first ? (
                            <Link href={yearLink(year - 1)} preserveScroll aria-label={`Holidays in ${year - 1}`}>
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        ) : (
                            <ChevronLeft aria-hidden="true" />
                        )}
                    </Button>
                    <h2 className="font-display min-w-[4.5rem] text-center text-xl font-semibold tracking-[-0.02em]" data-numeric aria-live="polite">
                        {year}
                    </h2>
                    <Button asChild={year < yearRange.last} variant="ghost" size="icon" className="size-9" disabled={year >= yearRange.last}>
                        {year < yearRange.last ? (
                            <Link href={yearLink(year + 1)} preserveScroll aria-label={`Holidays in ${year + 1}`}>
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        ) : (
                            <ChevronRight aria-hidden="true" />
                        )}
                    </Button>
                </div>
                {year !== currentYear && (
                    <Link
                        href={yearLink(currentYear)}
                        preserveScroll
                        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-sm text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                    >
                        Back to {currentYear}
                    </Link>
                )}
                <p className="text-muted-foreground text-sm sm:ml-auto">
                    <span data-numeric>{plural(holidays.length, 'holiday', 'holidays')}</span>
                    {holidays.length > 0 && (
                        <>
                            {' '}
                            · <span data-numeric>{weekdays}</span> on a weekday
                        </>
                    )}
                </p>
            </nav>

            {can.manage && forThisYear === 0 && (
                <div className="border-warning/30 bg-warning-surface text-warning flex gap-3 rounded-lg border p-4 text-sm" role="status">
                    <TriangleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                    <p>
                        <span className="font-semibold">No movable holidays or special days for {year} yet.</span> Holy Week, National Heroes Day,
                        Eid&apos;l Fitr, Eid&apos;l Adha and the special non-working days change every year. Add them once they are proclaimed, or
                        leave encoded on those days will count as leave.
                    </p>
                </div>
            )}

            {can.manage && (
                <form onSubmit={add} className="bg-card rounded-lg border p-4 sm:p-5" aria-labelledby="add-holiday">
                    <div className="form-rule mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pb-3">
                        <h2 id="add-holiday" className="font-display text-base font-semibold tracking-[-0.01em]">
                            Add a holiday
                        </h2>
                        <p className="text-muted-foreground text-xs">Encoding skips it as soon as it is saved.</p>
                    </div>
                    <HolidayFields form={addForm} kinds={kinds} idPrefix="add" />
                    <div className="mt-5 flex justify-end">
                        <Button type="submit" disabled={addForm.processing} className="w-full sm:w-auto">
                            {addForm.processing ? <Loader2 className="animate-spin" aria-hidden="true" /> : <Plus aria-hidden="true" />}
                            Add holiday
                        </Button>
                    </div>
                </form>
            )}

            <section className="bg-card overflow-hidden rounded-lg border" aria-label={`Holidays in ${year}`}>
                {holidays.length === 0 ? (
                    <EmptyState
                        icon={CalendarOff}
                        title={`No holidays in ${year}`}
                        description={
                            can.manage
                                ? 'Add the holidays for this year above. Fixed-date ones, like Christmas, are entered once and appear in every year.'
                                : 'An HR Admin enters the holidays. Until then, encoding counts every weekday in this year as a working day.'
                        }
                    />
                ) : (
                    <ol className="divide-y">
                        {holidays.map((holiday, index) => (
                            <motion.li
                                key={`${year}-${holiday.id}`}
                                initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={reduceMotion ? { duration: 0 } : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: EASE }}
                                className="px-4 py-3"
                            >
                                {editing?.id === holiday.id ? (
                                    <form onSubmit={saveEdit} className="py-1" aria-label={`Edit ${holiday.name}`}>
                                        <HolidayFields
                                            form={editForm}
                                            kinds={kinds}
                                            idPrefix={`edit-${holiday.id}`}
                                            recurringNote={holiday.recursYearly}
                                        />
                                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                                            <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(null)}>
                                                <X aria-hidden="true" />
                                                Cancel
                                            </Button>
                                            <Button type="submit" size="sm" disabled={editForm.processing}>
                                                {editForm.processing ? (
                                                    <Loader2 className="animate-spin" aria-hidden="true" />
                                                ) : (
                                                    <Check aria-hidden="true" />
                                                )}
                                                Save changes
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <div className="flex min-w-0 flex-1 items-center gap-4">
                                            <DayLeaf date={holiday.date} />
                                            <div className="min-w-0">
                                                <p className="font-medium">{holiday.name}</p>
                                                <p className="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <Badge variant="muted">{holiday.kindLabel}</Badge>
                                                    {holiday.recursYearly && (
                                                        <Badge variant="outline" title="Entered once; the same day every year">
                                                            <Repeat aria-hidden="true" className="size-3" />
                                                            Every year
                                                        </Badge>
                                                    )}
                                                    {holiday.leaveRecords > 0 && (
                                                        <Badge variant="warning" title="Saved while this was a working day. Check them on the tally.">
                                                            <TriangleAlert aria-hidden="true" className="size-3" />
                                                            Leave on file: {plural(holiday.leaveEmployees, 'employee', 'employees')}
                                                        </Badge>
                                                    )}
                                                </p>
                                            </div>
                                        </div>

                                        {can.manage && (
                                            <div className="flex shrink-0 items-center justify-end gap-1.5">
                                                <Button type="button" size="sm" variant="outline" onClick={() => startEditing(holiday)}>
                                                    <Pencil aria-hidden="true" />
                                                    Edit
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setRemoving(holiday)}
                                                    className="text-destructive hover:text-destructive"
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                    Remove
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </motion.li>
                        ))}
                    </ol>
                )}
            </section>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                processing={processing}
                tone="destructive"
                title={`Remove ${removing?.name ?? 'this holiday'}?`}
                confirmLabel="Remove holiday"
                onConfirm={confirmRemove}
                description={
                    <p>
                        {removing?.recursYearly ? 'It repeats every year, so it is removed from every year. ' : `It is removed from ${year} only. `}
                        That day becomes a working day for new leave. Leave already encoded does not change.
                    </p>
                }
            />
        </AppLayout>
    );
}
