import { DataPagination } from '@/components/data-pagination';
import { DeleteEmployeesDialog } from '@/components/delete-employees-dialog';
import { EmployeeSelectionBar } from '@/components/employee-selection-bar';
import { EmptyState } from '@/components/empty-state';
import { EraseEmployeesDialog } from '@/components/erase-employees-dialog';
import { ImportEmployeesDialog } from '@/components/import-employees-dialog';
import { LetterTabs } from '@/components/letter-tabs';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type EmployeeRecord, type OfficeOption, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Pencil, RotateCcw, Search, Trash2, UserPlus, UsersRound, X } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

interface EmployeeFilters {
    search: string;
    letter: string;
    office: string;
    status: string;
}

interface EmployeesIndexProps {
    employees: Paginated<EmployeeRecord>;
    offices: OfficeOption[];
    letters: string[];
    filters: EmployeeFilters;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employees', href: '/employees' }];

const ALL = 'all';
const SEARCH_DEBOUNCE_MS = 300;

export default function EmployeesIndex({ employees, offices, letters, filters }: EmployeesIndexProps) {
    const { auth } = usePage<SharedData>().props;
    const reduceMotion = useReducedMotion();
    const [search, setSearch] = useState(filters.search);
    const [importing, setImporting] = useState(false);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const canSeeSalary = auth.user?.can.viewSalary ?? false;
    // Deleting a record is the same privilege as taking someone off the list,
    // so HR Staff never see a tick-box at all. Reading the deleted records and
    // restoring from them is the same privilege again; erasing one for good is
    // deliberately not — it is its own permission, and an HR Admin without it
    // gets the list and the Restore button and no tick-boxes.
    const canDelete = auth.user?.can.deactivateEmployees ?? false;
    const canErase = auth.user?.can.eraseEmployees ?? false;
    const showingDeleted = filters.status === 'deleted';
    const canTick = showingDeleted ? canErase : canDelete;
    const hasFilters = filters.search !== '' || filters.letter !== '' || filters.office !== '' || filters.status !== '';

    const pageIds = employees.data.map((employee) => employee.id);
    const pageKey = pageIds.join(',');

    // Ticks belong to the rows on screen. Changing the search, letter tab, office
    // or page puts a different set of employees in front of HR, and a selection
    // carried over would act on names nobody can see any more.
    useEffect(() => {
        // Keeps the identity when there is nothing to clear, so arriving on the
        // page does not cost an extra render.
        setSelectedIds((current) => (current.length === 0 ? current : []));
    }, [pageKey]);

    const selectedEmployees = employees.data.filter((employee) => selectedIds.includes(employee.id));
    const keptCount = selectedEmployees.filter((employee) => (employee.leaveRecordsCount ?? 0) > 0).length;
    const allOnPageSelected = employees.data.length > 0 && selectedEmployees.length === employees.data.length;
    const selectAllState = allOnPageSelected ? true : selectedEmployees.length > 0 ? 'indeterminate' : false;

    const toggleRow = (id: number, checked: boolean) =>
        setSelectedIds((current) => (checked ? [...current, id] : current.filter((candidate) => candidate !== id)));

    const applyFilters = (changes: Partial<EmployeeFilters>) => {
        const next = { ...filters, ...changes };

        router.get(
            route('employees.index'),
            {
                search: next.search || undefined,
                letter: next.letter || undefined,
                office: next.office || undefined,
                status: next.status || undefined,
            },
            // replace, so typing a surname does not leave one history entry
            // per keystroke behind the back button.
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
        router.get(route('employees.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    // No confirmation in front of restoring: it puts a record back the way it
    // was, and the way to undo it is the delete button HR already knows.
    const restore = (employee: EmployeeRecord) => router.patch(route('employees.restore', employee.id), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employees" />

            <PageHeader
                eyebrow="Records"
                title="Employees"
                description="The masterlist the yearly tally sheet is drawn from. Someone who leaves the city government is deactivated, not removed, so the sheets they already appear on keep reading correctly."
                actions={
                    <>
                        <Button type="button" variant="outline" onClick={() => setImporting(true)} className="cursor-pointer">
                            <FileSpreadsheet aria-hidden="true" />
                            Import from Excel
                        </Button>
                        <Button asChild>
                            <Link href={route('employees.create')}>
                                <UserPlus aria-hidden="true" />
                                Add employee
                            </Link>
                        </Button>
                    </>
                }
            />

            <ImportEmployeesDialog open={importing} onOpenChange={setImporting} canSeeSalary={canSeeSalary} />

            {/*
              overflow-clip, not overflow-hidden: both clip the table to the
              card's rounded corners, but overflow-hidden makes this a scroll
              container, which would stop the sticky selection bar below from
              ever sticking to the viewport.
            */}
            <section className="border-border/80 bg-card overflow-clip rounded-xl border shadow-xs">
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
                                placeholder="Surname, first name, position or employee no."
                                className="h-11 rounded-lg pl-10"
                                autoComplete="off"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5 lg:w-56">
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

                    <div className="grid gap-1.5 lg:w-44">
                        <Label htmlFor="status" className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                            Status
                        </Label>
                        <Select
                            value={filters.status || 'active'}
                            onValueChange={(value) => applyFilters({ status: value === 'active' ? '' : value })}
                        >
                            <SelectTrigger id="status" className="h-11 cursor-pointer rounded-lg">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">On the list</SelectItem>
                                <SelectItem value="inactive">Deactivated</SelectItem>
                                <SelectItem value={ALL}>Everyone</SelectItem>
                                {canDelete && <SelectItem value="deleted">Deleted records</SelectItem>}
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

                <div className="border-b px-4 py-3">
                    <LetterTabs available={letters} value={filters.letter} onChange={(letter) => applyFilters({ letter })} />
                </div>

                {showingDeleted && (
                    <div className="border-b px-4 py-3">
                        <p className="text-muted-foreground text-sm">
                            Records deleted from the masterlist. They are off every list and out of leave encoding, but nothing has been lost:{' '}
                            <strong className="text-foreground">Restore</strong> puts one back exactly as it was.
                            {canErase
                                ? ' Erasing a record destroys it for good, so use it only for a row that should never have existed — a duplicate, a test row, a line imported twice.'
                                : ' Erasing one for good needs a permission your account does not have.'}
                        </p>
                    </div>
                )}

                {employees.data.length === 0 ? (
                    showingDeleted ? (
                        <EmptyState
                            icon={Trash2}
                            title="Nothing in the deleted records"
                            description="Records an HR Admin deletes from the masterlist wait here until they are restored or erased. Nothing matching these filters has been deleted."
                            action={
                                <Button type="button" variant="outline" onClick={clearFilters} className="cursor-pointer">
                                    Back to the masterlist
                                </Button>
                            }
                        />
                    ) : hasFilters ? (
                        <EmptyState
                            icon={Search}
                            title="Nobody matches this search"
                            description="Check the spelling of the surname, or clear the filters to see the whole masterlist."
                            action={
                                <Button type="button" variant="outline" onClick={clearFilters} className="cursor-pointer">
                                    Clear filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={UsersRound}
                            title="The masterlist is empty"
                            description="Add the employees this office keeps leave records for, one at a time or the whole masterlist from a filled-in Excel template. You can encode leave for them once they are on the list."
                            action={
                                <div className="flex flex-wrap justify-center gap-2">
                                    <Button type="button" variant="outline" onClick={() => setImporting(true)} className="cursor-pointer">
                                        <FileSpreadsheet aria-hidden="true" />
                                        Import from Excel
                                    </Button>
                                    <Button asChild>
                                        <Link href={route('employees.create')}>
                                            <UserPlus aria-hidden="true" />
                                            Add employee
                                        </Link>
                                    </Button>
                                </div>
                            }
                        />
                    )
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {canTick && (
                                    <TableHead className="w-10 pr-0">
                                        <Checkbox
                                            checked={selectAllState}
                                            onCheckedChange={(checked) => setSelectedIds(checked === true ? pageIds : [])}
                                            aria-label={allOnPageSelected ? 'Clear the selection' : 'Select every employee on this page'}
                                            className="cursor-pointer"
                                        />
                                    </TableHead>
                                )}
                                <TableHead>Employee</TableHead>
                                <TableHead className="hidden md:table-cell">Position</TableHead>
                                <TableHead className="hidden lg:table-cell">Office</TableHead>
                                {canSeeSalary && <TableHead className="hidden text-right xl:table-cell">Monthly rate</TableHead>}
                                <TableHead className="w-12 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.data.map((employee, index) => (
                                <motion.tr
                                    key={employee.id}
                                    initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={
                                        reduceMotion
                                            ? { duration: 0 }
                                            : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: [0.22, 1, 0.36, 1] }
                                    }
                                    data-state={selectedIds.includes(employee.id) ? 'selected' : undefined}
                                    className="hover:bg-accent/40 data-[state=selected]:bg-primary/5 border-b transition-colors last:border-0"
                                >
                                    {canTick && (
                                        <TableCell className="py-2 pr-0">
                                            <Checkbox
                                                checked={selectedIds.includes(employee.id)}
                                                onCheckedChange={(checked) => toggleRow(employee.id, checked === true)}
                                                aria-label={`Select ${employee.listName}`}
                                                className="cursor-pointer"
                                            />
                                        </TableCell>
                                    )}
                                    <TableCell className="py-2">
                                        {/*
                                          The width ceiling is what lets the row
                                          fit a 375px screen: an employee number
                                          does not wrap, so without it the
                                          column takes its min-content width and
                                          pushes the table sideways.
                                        */}
                                        <div className="max-w-[52vw] min-w-0 md:max-w-none">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate font-medium">{employee.listName}</span>
                                                {showingDeleted ? (
                                                    <Badge variant="danger" className="shrink-0">
                                                        Deleted {formatDeletedOn(employee.deletedAt)}
                                                    </Badge>
                                                ) : (
                                                    !employee.isActive && (
                                                        <Badge variant="muted" className="shrink-0">
                                                            Deactivated
                                                        </Badge>
                                                    )
                                                )}
                                            </div>
                                            <p className="text-muted-foreground truncate text-xs" data-numeric>
                                                {employee.employeeNo}
                                                {employee.employmentStatus && <span className="ml-2">· {employee.employmentStatus}</span>}
                                            </p>
                                            {/*
                                              Position and office move under the
                                              name on a phone: as columns of
                                              their own they took more width
                                              than the name they describe.
                                            */}
                                            <p className="text-muted-foreground mt-1 truncate text-xs md:hidden">
                                                {employee.positionTitle}
                                                {employee.officeName && <span> · {employee.officeName}</span>}
                                            </p>
                                        </div>
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate">{employee.positionTitle}</span>
                                            {employee.salaryGrade !== null && (
                                                <Badge variant="outline" className="shrink-0" data-numeric>
                                                    SG {employee.salaryGrade}
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground hidden lg:table-cell">{employee.officeName ?? '—'}</TableCell>
                                    {canSeeSalary && (
                                        <TableCell className="hidden text-right xl:table-cell" data-numeric>
                                            {formatRate(employee.monthlyRate)}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right">
                                        {/*
                                          Deactivating lives on the edit page, so
                                          editing is the only row action on the
                                          masterlist. A deleted record has no edit
                                          page to go to — putting it back is the
                                          only thing to do with one, and erasing
                                          it is in the selection bar, where it
                                          cannot be hit by a mis-click.
                                        */}
                                        {showingDeleted ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => restore(employee)}
                                                className="cursor-pointer"
                                            >
                                                <RotateCcw aria-hidden="true" />
                                                Restore
                                                <span className="sr-only"> {employee.listName}</span>
                                            </Button>
                                        ) : (
                                            <Button variant="ghost" size="icon" className="size-9 cursor-pointer" asChild>
                                                <Link href={route('employees.edit', employee.id)} title={`Edit ${employee.listName}`}>
                                                    <Pencil aria-hidden="true" />
                                                    <span className="sr-only">Edit {employee.listName}</span>
                                                </Link>
                                            </Button>
                                        )}
                                    </TableCell>
                                </motion.tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                {canTick && (
                    <EmployeeSelectionBar
                        selectedCount={selectedEmployees.length}
                        keptCount={keptCount}
                        mode={showingDeleted ? 'erase' : 'delete'}
                        onClear={() => setSelectedIds([])}
                        onDelete={() => setConfirmingDelete(true)}
                    />
                )}

                <DataPagination meta={employees.meta} links={employees.links} label="employees" />
            </section>

            {canTick &&
                (showingDeleted ? (
                    <EraseEmployeesDialog
                        open={confirmingDelete}
                        onOpenChange={setConfirmingDelete}
                        selected={selectedEmployees}
                        onErased={() => setSelectedIds([])}
                    />
                ) : (
                    <DeleteEmployeesDialog
                        open={confirmingDelete}
                        onOpenChange={setConfirmingDelete}
                        selected={selectedEmployees}
                        onDeleted={() => setSelectedIds([])}
                    />
                ))}
        </AppLayout>
    );
}

/**
 * When the row was deleted, short enough to sit inside a badge. Dates only —
 * what HR wants from this is how stale the record is, not the minute.
 */
function formatDeletedOn(deletedAt: string | null): string {
    if (!deletedAt) {
        return '';
    }

    const date = new Date(deletedAt);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * The rate arrives as a decimal string so the centavos stay exact; it is only
 * grouped for reading here, never recomputed.
 */
function formatRate(rate: string | null | undefined): string {
    if (rate === null || rate === undefined || rate === '') {
        return '—';
    }

    const value = Number(rate);

    return Number.isNaN(value) ? rate : value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
