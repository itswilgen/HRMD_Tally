import { EmptyState } from '@/components/empty-state';
import { LeaveChip } from '@/components/leave-chip';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { MONTH_LABELS } from '@/lib/months';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { CalendarRange, ChevronDown, History, Pencil, Scale } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useState } from 'react';

interface HistoryEmployee {
    id: number;
    employeeNo: string;
    listName: string;
    positionTitle: string;
    officeName: string | null;
    isActive: boolean;
}

interface HistoryDay {
    id: number;
    date: string;
    leaveTypeId: number;
    code: string;
    name: string;
    color: string;
    dayPart: 'WHOLE' | 'AM' | 'PM';
    dayPartLabel: string;
    remarks: string | null;
    encodedBy: string | null;
    encodedAt: string | null;
}

interface HistoryYear {
    year: number;
    grandTotal: number;
    grandTotalText: string;
    totals: { leaveTypeId: number; code: string; name: string; color: string; days: number; daysText: string }[];
    months: { month: number; entries: { code: string; color: string; dayFigures: string; text: string }[]; daysText: string }[];
    days: HistoryDay[];
}

interface LeaveHistoryPageProps {
    employee: HistoryEmployee;
    history: { years: HistoryYear[]; totalDays: number; totalDaysText: string; firstYear: number | null };
    currentYear: number;
    can: { viewCredits: boolean; editEmployee: boolean };
}

const EASE = [0.22, 1, 0.36, 1] as const;

/** Y-m-d read as a calendar day, never through UTC, so it cannot slip a day. */
function parseDay(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function formatDay(value: string): string {
    return parseDay(value).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
}

function daysLabel(text: string, value: number): string {
    return `${text} ${value === 1 ? 'day' : 'days'}`;
}

/**
 * The twelve months as the tally prints them, from the same service. Months
 * still ahead in the current year are muted, as on the tally.
 */
function MonthStrip({ year, months, currentYear }: { year: number; months: HistoryYear['months']; currentYear: number }) {
    const thisMonth = new Date().getMonth() + 1;

    return (
        // A 1px gap over the border colour draws the cell lines, so they are
        // right at 3, 6 and 12 columns without per-breakpoint border rules.
        <ol className="bg-border grid grid-cols-3 gap-px border-y sm:grid-cols-6 xl:grid-cols-12" aria-label={`${year}, month by month`}>
            {months.map((month) => {
                const ahead = year > currentYear || (year === currentYear && month.month > thisMonth);

                return (
                    <li key={month.month} className={cn('bg-card grid min-h-14 content-start gap-1.5 p-2.5 sm:min-h-20', ahead && 'bg-muted')}>
                        <span className={cn('text-2xs font-semibold tracking-wider', ahead ? 'text-muted-foreground/60' : 'text-muted-foreground')}>
                            {MONTH_LABELS[month.month - 1]}
                        </span>
                        {month.entries.length === 0 ? (
                            <span className="text-muted-foreground/50 text-xs" aria-label="No leave">
                                —
                            </span>
                        ) : (
                            <span className="flex flex-wrap gap-1">
                                {month.entries.map((entry) => (
                                    <LeaveChip key={entry.text} color={entry.color} code={entry.code} figures={entry.dayFigures} title={entry.text} />
                                ))}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

function DaysTable({ year }: { year: HistoryYear }) {
    return (
        <>
            {/* A table where there is room for one, a list on a phone. */}
            <table className="hidden w-full text-left text-sm md:table">
                <caption className="sr-only">Every leave day in {year.year}</caption>
                <thead className="bg-table-head text-muted-foreground text-2xs font-semibold tracking-[0.04em] uppercase">
                    <tr>
                        <th scope="col" className="px-4 py-2.5">
                            Date
                        </th>
                        <th scope="col" className="px-4 py-2.5">
                            Leave
                        </th>
                        <th scope="col" className="px-4 py-2.5">
                            Part
                        </th>
                        <th scope="col" className="px-4 py-2.5">
                            Remarks
                        </th>
                        <th scope="col" className="px-4 py-2.5">
                            Encoded by
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {year.days.map((day) => (
                        <tr key={day.id}>
                            <td className="px-4 py-2.5 whitespace-nowrap" data-numeric>
                                <time dateTime={day.date}>{formatDay(day.date)}</time>
                            </td>
                            <td className="px-4 py-2.5">
                                <span className="flex items-center gap-2">
                                    <LeaveChip color={day.color} code={day.code} />
                                    <span className="text-muted-foreground truncate">{day.name}</span>
                                </span>
                            </td>
                            <td className="px-4 py-2.5 whitespace-nowrap">{day.dayPart === 'WHOLE' ? 'Whole day' : day.dayPart}</td>
                            <td className="text-muted-foreground max-w-[28ch] px-4 py-2.5">
                                <span className="line-clamp-2">{day.remarks ?? '—'}</span>
                            </td>
                            <td className="text-muted-foreground px-4 py-2.5 whitespace-nowrap">
                                {day.encodedBy ?? '—'}
                                {day.encodedAt && (
                                    <span className="text-2xs block" data-numeric>
                                        {parseDay(day.encodedAt).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <ul className="divide-y md:hidden" aria-label={`Every leave day in ${year.year}`}>
                {year.days.map((day) => (
                    <li key={day.id} className="grid gap-1 px-4 py-3 text-sm">
                        <span className="flex items-center justify-between gap-3">
                            <time dateTime={day.date} className="font-medium" data-numeric>
                                {formatDay(day.date)}
                            </time>
                            <span className="flex items-center gap-2">
                                {day.dayPart !== 'WHOLE' && <span className="text-muted-foreground text-xs">{day.dayPart}</span>}
                                <LeaveChip color={day.color} code={day.code} />
                            </span>
                        </span>
                        {day.remarks && <span className="text-muted-foreground text-xs">{day.remarks}</span>}
                        {day.encodedBy && <span className="text-muted-foreground text-2xs">Encoded by {day.encodedBy}</span>}
                    </li>
                ))}
            </ul>
        </>
    );
}

function YearCard({ year, open, currentYear, index }: { year: HistoryYear; open: boolean; currentYear: number; index: number }) {
    const reduceMotion = useReducedMotion();
    const [showDays, setShowDays] = useState(open);

    return (
        <motion.section
            id={`year-${year.year}`}
            aria-labelledby={`year-${year.year}-title`}
            initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={reduceMotion ? { duration: 0 } : { duration: 0.3, delay: Math.min(index, 4) * 0.05, ease: EASE }}
            className="bg-card scroll-mt-24 overflow-hidden rounded-lg border"
        >
            <header className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4 sm:px-5">
                <div className="flex items-baseline gap-3">
                    <h2 id={`year-${year.year}-title`} className="font-display text-2xl font-semibold tracking-[-0.02em]" data-numeric>
                        {year.year}
                    </h2>
                    <span className="text-muted-foreground text-sm" data-numeric>
                        {daysLabel(year.grandTotalText, year.grandTotal)}
                    </span>
                </div>
                <ul className="flex flex-wrap gap-1.5" aria-label={`Totals in ${year.year}`}>
                    {year.totals.map((total) => (
                        <li key={total.leaveTypeId}>
                            <LeaveChip
                                color={total.color}
                                code={total.code}
                                figures={total.daysText}
                                title={`${total.name}: ${daysLabel(total.daysText, total.days)}`}
                                className="text-xs"
                            />
                        </li>
                    ))}
                </ul>
            </header>

            <MonthStrip year={year.year} months={year.months} currentYear={currentYear} />

            <Collapsible open={showDays} onOpenChange={setShowDays}>
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-foreground hover:bg-muted/50 focus-visible:ring-ring flex w-full cursor-pointer items-center justify-between px-4 py-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset sm:px-5"
                    >
                        <span>
                            {showDays ? 'Hide' : 'Show'} the {year.days.length.toLocaleString()} {year.days.length === 1 ? 'entry' : 'entries'}
                        </span>
                        <ChevronDown aria-hidden="true" className={cn('size-4 transition-transform', showDays && 'rotate-180')} />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <DaysTable year={year} />
                </CollapsibleContent>
            </Collapsible>
        </motion.section>
    );
}

export default function LeaveHistory({ employee, history, currentYear, can }: LeaveHistoryPageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Leave tally', href: route('tally.index') },
        { title: employee.listName, href: route('employees.leave', employee.id) },
    ];

    const details = [`No. ${employee.employeeNo}`, employee.positionTitle, employee.officeName].filter(Boolean).join(' · ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.listName} — leave history`} />

            <PageHeader
                eyebrow="Leave history"
                title={employee.listName}
                description={details}
                actions={
                    (can.viewCredits || can.editEmployee) && (
                        <div className="flex flex-wrap gap-2">
                            {can.viewCredits && (
                                <Button variant="outline" asChild>
                                    <Link href={route('credits.show', { employee: employee.id, year: currentYear })}>
                                        <Scale aria-hidden="true" />
                                        Leave credits
                                    </Link>
                                </Button>
                            )}
                            {can.editEmployee && (
                                <Button variant="outline" asChild>
                                    <Link href={route('employees.edit', employee.id)}>
                                        <Pencil aria-hidden="true" />
                                        Edit employee
                                    </Link>
                                </Button>
                            )}
                        </div>
                    )
                }
            />

            {history.years.length === 0 ? (
                <section className="bg-card rounded-lg border">
                    <EmptyState
                        icon={History}
                        title="No leave on file yet"
                        description="Leave appears here once it is encoded on the tally sheet, every year in one place."
                        action={
                            <Button variant="outline" asChild>
                                <Link href={route('tally.index')}>
                                    <CalendarRange aria-hidden="true" />
                                    Open the tally
                                </Link>
                            </Button>
                        }
                    />
                </section>
            ) : (
                <>
                    <nav aria-label="Years on file" className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <p className="text-sm">
                            <span className="font-semibold" data-numeric>
                                {daysLabel(history.totalDaysText, history.totalDays)}
                            </span>{' '}
                            <span className="text-muted-foreground">of leave on file since {history.firstYear}</span>
                        </p>
                        {!employee.isActive && <Badge variant="muted">No longer active</Badge>}
                        {history.years.length > 1 && (
                            <ul className="flex flex-wrap gap-1.5 sm:ml-auto">
                                {history.years.map((year) => (
                                    <li key={year.year}>
                                        <a
                                            href={`#year-${year.year}`}
                                            className="bg-card hover:bg-muted focus-visible:ring-ring inline-flex h-8 items-center rounded-md border px-2.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                            data-numeric
                                        >
                                            {year.year}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </nav>

                    {history.years.map((year, index) => (
                        <YearCard key={year.year} year={year} open={index === 0} currentYear={currentYear} index={index} />
                    ))}
                </>
            )}
        </AppLayout>
    );
}
