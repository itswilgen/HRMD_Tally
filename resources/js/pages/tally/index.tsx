import { AddLeaveDialog, type AddLeaveTarget } from '@/components/add-leave-dialog';
import { DataPagination } from '@/components/data-pagination';
import { EmptyState } from '@/components/empty-state';
import { LeaveEntrySheet, type LeaveEntryTarget } from '@/components/leave-entry-sheet';
import { LeaveLegend } from '@/components/leave-legend';
import { LetterTabs } from '@/components/letter-tabs';
import { PageHeader } from '@/components/page-header';
import { TallyTable } from '@/components/tally-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { monthLabel } from '@/lib/months';
import { cn } from '@/lib/utils';
import {
    type BreadcrumbItem,
    type DayPartOption,
    type EmployeeRecord,
    type LeaveTypeColumn,
    type LeaveTypeOption,
    type OfficeOption,
    type Paginated,
    type SharedData,
    type TallyEntry,
    type TallyRow,
} from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, CalendarPlus, FileDown, FileSpreadsheet, Rows3, Search, Tag, UsersRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface TallyFilters {
    search: string;
    letter: string;
    office: string;
}

interface TallyIndexProps {
    employees: Paginated<EmployeeRecord>;
    /** Keyed by employee id; the row for anyone with no leave is simply absent. */
    tally: Record<string, TallyRow>;
    leaveTypes: LeaveTypeColumn[];
    encodableLeaveTypes: LeaveTypeOption[];
    dayParts: DayPartOption[];
    offices: OfficeOption[];
    letters: string[];
    year: number;
    years: number[];
    currentMonth: number | null;
    asOf: string;
    filters: TallyFilters;
}

/** Which chip is open: an address into the tally props, not a copy of it. */
interface OpenChip {
    employeeId: number;
    employeeName: string;
    month: number;
    leaveTypeId: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave tally', href: '/tally' }];

const ALL = 'all';
const SEARCH_DEBOUNCE_MS = 300;
const DENSITY_KEY = 'hrmd.tally.density';

export default function TallyIndex({
    employees,
    tally,
    leaveTypes,
    encodableLeaveTypes,
    dayParts,
    offices,
    letters,
    year,
    years,
    currentMonth,
    asOf,
    filters,
}: TallyIndexProps) {
    const { auth } = usePage<SharedData>().props;

    const canEncode = auth.user?.can.manageLeave ?? false;
    const canSeeSalary = auth.user?.can.viewSalary ?? false;
    const canExport = auth.user?.can.generateExports ?? false;

    const [search, setSearch] = useState(filters.search);
    const [compact, setCompact] = useState(false);
    const [addTarget, setAddTarget] = useState<AddLeaveTarget>({ employeeId: null, month: null });
    const [addOpen, setAddOpen] = useState(false);
    const [openChip, setOpenChip] = useState<OpenChip | null>(null);

    const hasFilters = filters.search !== '' || filters.letter !== '' || filters.office !== '';

    // Density is a per-person habit, not shared state, so it stays in this
    // browser. Reading it can throw in a locked-down browser; the sheet simply
    // opens comfortable in that case.
    useEffect(() => {
        try {
            setCompact(window.localStorage.getItem(DENSITY_KEY) === 'compact');
        } catch {
            setCompact(false);
        }
    }, []);

    const toggleDensity = () => {
        const next = !compact;
        setCompact(next);

        try {
            window.localStorage.setItem(DENSITY_KEY, next ? 'compact' : 'comfortable');
        } catch {
            // Not worth telling anyone about: the toggle still works for this visit.
        }
    };

    const applyFilters = (changes: Partial<TallyFilters & { year: number }>) => {
        const next = { ...filters, year, ...changes };

        router.get(
            route('tally.index'),
            {
                year: next.year,
                search: next.search || undefined,
                letter: next.letter || undefined,
                office: next.office || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // HR types surnames from a stack of paper forms, so the request waits until
    // they stop rather than firing on every letter.
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
        router.get(route('tally.index'), { year }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const openAdd = (employee: EmployeeRecord | null, month: number | null) => {
        setAddTarget({ employeeId: employee?.id ?? null, month });
        setAddOpen(true);
    };

    const openEntry = (employee: EmployeeRecord, month: number, entry: TallyEntry) => {
        setOpenChip({ employeeId: employee.id, employeeName: employee.listName, month, leaveTypeId: entry.leaveTypeId });
    };

    // Looked up from the props on every render instead of copied into state:
    // correcting a day re-renders the sheet, and a copy would still show the
    // date that was just changed.
    const entryTarget = findEntry(tally, openChip);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Leave tally sheet ${year}`} />

            <PageHeader
                eyebrow="Leave monitoring"
                title={`Leave tally sheet — ${year}`}
                description={`The yearly sheet as of ${formatAsOf(asOf)}. Every figure is counted from the encoded leave days themselves, so this page, the printout and the Excel export always agree.`}
                actions={
                    <>
                        {canExport && (
                            <>
                                {/*
                                  Listed and disabled rather than hidden: the
                                  paper process ends in a printed sheet, and the
                                  encoder should be able to see that is where
                                  this is going. The export phase wires them up.
                                */}
                                <Button variant="outline" disabled title="Added in the export phase" className="hidden sm:inline-flex">
                                    <FileDown aria-hidden="true" />
                                    Print (PDF)
                                </Button>
                                <Button variant="outline" disabled title="Added in the export phase" className="hidden lg:inline-flex">
                                    <FileSpreadsheet aria-hidden="true" />
                                    Excel
                                </Button>
                            </>
                        )}

                        {canEncode && (
                            <Button type="button" onClick={() => openAdd(null, currentMonth)} className="cursor-pointer">
                                <CalendarPlus aria-hidden="true" />
                                Add leave
                            </Button>
                        )}
                    </>
                }
            />

            {/* Summary Metrics & Context */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="border-border/70 bg-card flex items-center gap-3 rounded-xl border p-3.5 shadow-2xs transition-all hover:shadow-xs">
                    <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <UsersRound className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-2xs text-muted-foreground font-semibold tracking-wider uppercase">Personnel</p>
                        <p className="text-foreground text-lg font-bold tracking-tight" data-numeric>
                            {employees.meta.total}
                        </p>
                    </div>
                </div>

                <div className="border-border/70 bg-card flex items-center gap-3 rounded-xl border p-3.5 shadow-2xs transition-all hover:shadow-xs">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <CalendarDays className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-2xs text-muted-foreground font-semibold tracking-wider uppercase">Tally Period</p>
                        <p className="text-foreground text-lg font-bold tracking-tight" data-numeric>
                            {year}
                        </p>
                    </div>
                </div>

                <div className="border-border/70 bg-card flex items-center gap-3 rounded-xl border p-3.5 shadow-2xs transition-all hover:shadow-xs">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <Tag className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-2xs text-muted-foreground font-semibold tracking-wider uppercase">Leave Codes</p>
                        <p className="text-foreground text-lg font-bold tracking-tight" data-numeric>
                            {leaveTypes.length} Types
                        </p>
                    </div>
                </div>

                <div className="border-border/70 bg-card flex items-center gap-3 rounded-xl border p-3.5 shadow-2xs transition-all hover:shadow-xs">
                    <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <Rows3 className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-2xs text-muted-foreground font-semibold tracking-wider uppercase">View Density</p>
                        <p className="text-foreground text-sm font-bold tracking-tight">{compact ? 'Compact' : 'Comfortable'}</p>
                    </div>
                </div>
            </div>

            <section className="border-border/80 bg-card overflow-hidden rounded-xl border shadow-xs">
                <div className="flex flex-col gap-3.5 border-b p-4 lg:flex-row lg:items-end">
                    <div className="grid gap-1.5 lg:w-28">
                        <Label htmlFor="year" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Year
                        </Label>
                        <Select value={String(year)} onValueChange={(value) => applyFilters({ year: Number(value) })}>
                            <SelectTrigger id="year" className="h-11 cursor-pointer rounded-lg font-medium" data-numeric>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {years.map((option) => (
                                    <SelectItem key={option} value={String(option)}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

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
                                placeholder="Search employee name or position..."
                                className="h-11 rounded-lg pl-10"
                                autoComplete="off"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5 lg:w-64">
                        <Label htmlFor="office" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Office
                        </Label>
                        <Select
                            value={filters.office || ALL}
                            onValueChange={(value) => applyFilters({ office: value === ALL ? '' : value, letter: '' })}
                        >
                            <SelectTrigger id="office" className="h-11 cursor-pointer rounded-lg">
                                <SelectValue placeholder="All offices" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All offices</SelectItem>
                                {offices.map((office) => (
                                    <SelectItem key={office.value} value={String(office.value)}>
                                        {office.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant={compact ? 'secondary' : 'outline'}
                            onClick={toggleDensity}
                            aria-pressed={compact}
                            className={cn('h-11 cursor-pointer rounded-lg font-semibold', compact && 'border-primary/30 bg-primary/10 text-primary')}
                            title={compact ? 'Switch to comfortable rows' : 'Fit more rows on screen'}
                        >
                            <Rows3 aria-hidden="true" />
                            {compact ? 'Compact' : 'Comfortable'}
                        </Button>

                        {hasFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={clearFilters}
                                className="text-muted-foreground hover:text-foreground h-11 cursor-pointer rounded-lg"
                            >
                                <X aria-hidden="true" />
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-3 border-b px-4 py-3">
                    <LetterTabs available={letters} value={filters.letter} onChange={(letter) => applyFilters({ letter })} />
                    <LeaveLegend leaveTypes={leaveTypes} />
                </div>

                {employees.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState
                            icon={Search}
                            title="No employees match this search or office"
                            description="Check the spelling of the surname, or clear the filters to see the whole sheet."
                            action={
                                <Button type="button" variant="outline" onClick={clearFilters} className="cursor-pointer">
                                    Clear filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={UsersRound}
                            title={`Nobody is on the ${year} sheet yet`}
                            description="The sheet lists the employees currently on the masterlist, plus anyone with leave on file for this year."
                        />
                    )
                ) : (
                    <TallyTable
                        employees={employees.data}
                        tally={tally}
                        leaveTypes={leaveTypes}
                        year={year}
                        currentMonth={currentMonth}
                        firstRowNumber={employees.meta.from ?? 1}
                        canSeeSalary={canSeeSalary}
                        canEncode={canEncode}
                        compact={compact}
                        onAdd={(employee, month) => openAdd(employee, month)}
                        onOpenEntry={openEntry}
                    />
                )}

                <DataPagination meta={employees.meta} links={employees.links} label="employees" note="Sorted by last name" />
            </section>

            {canEncode && (
                <AddLeaveDialog
                    open={addOpen}
                    onOpenChange={setAddOpen}
                    employees={employees.data}
                    leaveTypes={encodableLeaveTypes}
                    dayParts={dayParts}
                    year={year}
                    target={addTarget}
                />
            )}

            <LeaveEntrySheet
                open={entryTarget !== null}
                onOpenChange={(open) => !open && setOpenChip(null)}
                target={entryTarget}
                leaveTypes={encodableLeaveTypes}
                dayParts={dayParts}
                year={year}
                canEdit={canEncode}
                canDelete={canEncode}
            />
        </AppLayout>
    );
}

/**
 * The chip an address points at, or null once its last day has been removed —
 * which is also the signal to close the panel.
 */
function findEntry(tally: Record<string, TallyRow>, chip: OpenChip | null): LeaveEntryTarget | null {
    if (chip === null) {
        return null;
    }

    const entry = tally[String(chip.employeeId)]?.months
        .find((month) => month.month === chip.month)
        ?.entries.find((candidate) => candidate.leaveTypeId === chip.leaveTypeId);

    return entry === undefined ? null : { employeeName: chip.employeeName, monthLabel: monthLabel(chip.month), entry };
}

/** "2026-10-31" as "31 October 2026", the way the office writes a date. */
function formatAsOf(date: string): string {
    const parsed = new Date(`${date}T00:00:00`);

    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString('en-PH', { day: 'numeric', month: 'long', year: 'numeric' });
}
