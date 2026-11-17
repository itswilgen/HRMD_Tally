import { CopyCreditsDialog } from '@/components/copy-credits-dialog';
import { type CreditDrafts, CreditsGrid } from '@/components/credits-grid';
import { DataPagination } from '@/components/data-pagination';
import { EmptyState } from '@/components/empty-state';
import { GrantCreditsDialog } from '@/components/grant-credits-dialog';
import { ImportCreditsDialog } from '@/components/import-credits-dialog';
import { LetterTabs } from '@/components/letter-tabs';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { figurePayload, looksLikeFigure } from '@/lib/credits';
import { type BreadcrumbItem, type CreditColumn, type CreditRow, type EmployeeRecord, type OfficeOption, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Copy, FileUp, ListPlus, Loader2, Save, Search, UsersRound, X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface CreditFilters {
    search: string;
    letter: string;
    office: string;
}

interface CreditsIndexProps {
    employees: Paginated<EmployeeRecord>;
    /** Keyed by employee id: every employee on the page has a row. */
    credits: Record<string, CreditRow>;
    leaveTypes: CreditColumn[];
    offices: OfficeOption[];
    letters: string[];
    year: number;
    years: number[];
    filters: CreditFilters;
    can: { manage: boolean };
}

type OpenDialog = 'grant' | 'copy' | 'import' | null;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave credits', href: '/credits' }];

const ALL = 'all';
const SEARCH_DEBOUNCE_MS = 300;

export default function CreditsIndex({ employees, credits, leaveTypes, offices, letters, year, years, filters, can }: CreditsIndexProps) {
    const reduceMotion = useReducedMotion();
    const [search, setSearch] = useState(filters.search);
    const [drafts, setDrafts] = useState<CreditDrafts>({});
    const [cellErrors, setCellErrors] = useState<Record<string, string>>({});
    const [saveError, setSaveError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [dialog, setDialog] = useState<OpenDialog>(null);

    const hasFilters = filters.search !== '' || filters.letter !== '' || filters.office !== '';
    const activeColumns = leaveTypes.filter((column) => column.isActive);

    const changes = useMemo(() => {
        const employeeIds = Object.keys(drafts);

        return {
            employees: employeeIds.length,
            figures: employeeIds.reduce((count, id) => count + Object.keys(drafts[id]).length, 0),
            invalid: employeeIds.some((id) => Object.values(drafts[id]).some((value) => !looksLikeFigure(value))),
        };
    }, [drafts]);

    const dirty = changes.figures > 0;

    // Unsaved figures are easy to lose by changing the year or the page, so
    // any visit away asks first. Saving and the dialogs are not GET visits and
    // pass straight through.
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const removeGuard = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm('You have unsaved credit changes. Leave without saving them?')) {
                event.preventDefault();
            }
        });

        const onUnload = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', onUnload);

        return () => {
            removeGuard();
            window.removeEventListener('beforeunload', onUnload);
        };
    }, [dirty]);

    const applyFilters = (next: Partial<CreditFilters & { year: number }>) => {
        const merged = { ...filters, year, ...next };

        router.get(
            route('credits.index'),
            {
                year: merged.year,
                search: merged.search || undefined,
                letter: merged.letter || undefined,
                office: merged.office || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, onSuccess: () => discard() },
        );
    };

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
        router.get(route('credits.index'), { year }, { preserveState: true, preserveScroll: true, replace: true, onSuccess: () => discard() });
    };

    const changeCell = (employeeId: number, leaveTypeId: number, value: string | null) => {
        const employeeKey = String(employeeId);
        const typeKey = String(leaveTypeId);

        setDrafts((current) => {
            const row = { ...(current[employeeKey] ?? {}) };

            if (value === null) {
                delete row[typeKey];
            } else {
                row[typeKey] = value;
            }

            const next = { ...current };

            if (Object.keys(row).length === 0) {
                delete next[employeeKey];
            } else {
                next[employeeKey] = row;
            }

            return next;
        });

        setCellErrors((current) => {
            const key = `${employeeId}:${leaveTypeId}`;

            if (!(key in current)) {
                return current;
            }

            const next = { ...current };
            delete next[key];

            return next;
        });
    };

    const discard = () => {
        setDrafts({});
        setCellErrors({});
        setSaveError(null);
    };

    const save = () => {
        const employeeIds = Object.keys(drafts);

        router.patch(
            route('credits.update'),
            {
                year,
                rows: employeeIds.map((id) => ({
                    employee_id: Number(id),
                    credits: Object.fromEntries(Object.entries(drafts[id]).map(([typeId, value]) => [typeId, figurePayload(value)])),
                })),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => discard(),
                onError: (errors) => {
                    const byCell: Record<string, string> = {};
                    const general: string[] = [];

                    for (const [key, message] of Object.entries(errors)) {
                        const match = /^rows\.(\d+)\.credits\.(\d+)$/.exec(key);

                        if (match && employeeIds[Number(match[1])] !== undefined) {
                            byCell[`${employeeIds[Number(match[1])]}:${match[2]}`] = message;
                        } else {
                            general.push(message);
                        }
                    }

                    setCellErrors(byCell);
                    setSaveError(
                        general[0] ??
                            `${Object.keys(byCell).length} ${Object.keys(byCell).length === 1 ? 'figure needs' : 'figures need'} fixing. Hover a red cell to see why.`,
                    );
                },
            },
        );
    };

    const busyTitle = dirty ? 'Save or discard your changes first' : undefined;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Leave credits ${year}`} />

            <PageHeader
                eyebrow="Leave monitoring"
                title={`Leave credits — ${year}`}
                description="What each employee was granted for the year, against the days the tally counts as taken. A blank cell means no figure yet; 0 means none was granted."
                actions={
                    can.manage && (
                        <>
                            {/*
                              Icon-only below xl, so the title is never cut to
                              "Leave c…" beside four buttons. The label stays for
                              screen readers and as a tooltip. Downloading the
                              sheet lives in the Import dialog, where it is the
                              first step of the same job.
                            */}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialog('import')}
                                disabled={dirty}
                                title={busyTitle ?? 'Download the year’s sheet, fill it in Excel and import it'}
                                aria-label="Import from Excel"
                                className="cursor-pointer"
                            >
                                <FileUp aria-hidden="true" />
                                <span className="hidden xl:inline">Import</span>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialog('copy')}
                                disabled={dirty}
                                title={busyTitle ?? 'Copy another year’s figures into this one'}
                                aria-label="Copy a year"
                                className="cursor-pointer"
                            >
                                <Copy aria-hidden="true" />
                                <span className="hidden xl:inline">Copy a year</span>
                            </Button>
                            <Button type="button" onClick={() => setDialog('grant')} disabled={dirty} title={busyTitle} className="cursor-pointer">
                                <ListPlus aria-hidden="true" />
                                Grant to all
                            </Button>
                        </>
                    )
                }
            />

            <section className="bg-card overflow-hidden rounded-lg border">
                <div className="flex flex-col gap-3.5 border-b p-4 lg:flex-row lg:items-end">
                    <div className="grid gap-1.5 lg:w-28">
                        <Label htmlFor="year" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Year
                        </Label>
                        <Select value={String(year)} onValueChange={(value) => applyFilters({ year: Number(value) })}>
                            <SelectTrigger id="year" className="h-11 font-medium" data-numeric>
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
                                className="h-11 pl-10"
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
                            <SelectTrigger id="office" className="h-11">
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

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters} className="text-muted-foreground hover:text-foreground h-11">
                            <X aria-hidden="true" />
                            Clear filters
                        </Button>
                    )}
                </div>

                <div className="flex flex-col gap-2 border-b px-4 py-3">
                    <LetterTabs available={letters} value={filters.letter} onChange={(letter) => applyFilters({ letter })} />
                    <p className="text-muted-foreground text-xs">
                        Each cell: days granted, then taken and left for {year}.
                        {can.manage && ' Type a figure, press Enter to move down the column, Esc to undo a cell.'}
                    </p>
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
                            description="The sheet lists the employees currently on the masterlist, plus anyone with leave or credits on file for this year."
                        />
                    )
                ) : (
                    <CreditsGrid
                        employees={employees.data}
                        credits={credits}
                        columns={leaveTypes}
                        year={year}
                        firstRowNumber={employees.meta.from ?? 1}
                        canManage={can.manage}
                        drafts={drafts}
                        errors={cellErrors}
                        disabled={saving}
                        onChange={changeCell}
                    />
                )}

                <DataPagination meta={employees.meta} links={employees.links} label="employees" note="Sorted by last name" />
            </section>

            <AnimatePresence>
                {dirty && (
                    <motion.div
                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 12 }}
                        transition={{ type: 'spring', stiffness: 380, damping: 30 }}
                        role="region"
                        aria-label="Unsaved changes"
                        className="bg-card sticky bottom-4 z-30 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 pl-4 shadow-md"
                    >
                        <div className="min-w-0 text-sm" aria-live="polite">
                            <p className="font-semibold">
                                <span data-numeric>{changes.figures}</span> unsaved {changes.figures === 1 ? 'figure' : 'figures'} for{' '}
                                <span data-numeric>{changes.employees}</span> {changes.employees === 1 ? 'employee' : 'employees'}
                            </p>
                            {saveError ? (
                                <p className="text-destructive text-xs">{saveError}</p>
                            ) : changes.invalid ? (
                                <p className="text-destructive text-xs">Some figures are not in steps of 0.5 between 0 and 999.</p>
                            ) : (
                                <p className="text-muted-foreground text-xs">Empty a cell to remove its figure.</p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={discard} disabled={saving} className="cursor-pointer">
                                Discard
                            </Button>
                            <Button type="button" onClick={save} disabled={saving || changes.invalid} className="cursor-pointer">
                                {saving ? <Loader2 aria-hidden="true" className="animate-spin" /> : <Save aria-hidden="true" />}
                                Save changes
                            </Button>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {can.manage && (
                <>
                    <GrantCreditsDialog
                        open={dialog === 'grant'}
                        onOpenChange={(open) => setDialog(open ? 'grant' : null)}
                        year={year}
                        columns={activeColumns}
                        offices={offices}
                    />
                    <CopyCreditsDialog
                        key={year}
                        open={dialog === 'copy'}
                        onOpenChange={(open) => setDialog(open ? 'copy' : null)}
                        year={year}
                        years={years}
                    />
                    <ImportCreditsDialog open={dialog === 'import'} onOpenChange={(open) => setDialog(open ? 'import' : null)} year={year} />
                </>
            )}
        </AppLayout>
    );
}
