import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataPagination } from '@/components/data-pagination';
import { EmptyState } from '@/components/empty-state';
import { LetterTabs } from '@/components/letter-tabs';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type EmployeeRecord, type OfficeOption, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ellipsis, Pencil, Search, UserPlus, UserRoundCheck, UserRoundX, UsersRound, X } from 'lucide-react';
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
    const [pending, setPending] = useState<EmployeeRecord | null>(null);
    const [processing, setProcessing] = useState(false);

    const canSeeSalary = auth.user?.can.viewSalary ?? false;
    const canDeactivate = auth.user?.can.deactivateEmployees ?? false;
    const hasFilters = filters.search !== '' || filters.letter !== '' || filters.office !== '' || filters.status !== '';

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

    const reactivate = (employee: EmployeeRecord) => {
        router.patch(route('employees.activate', employee.id), {}, { preserveScroll: true });
    };

    const confirmDeactivate = () => {
        if (!pending) {
            return;
        }

        router.patch(
            route('employees.deactivate', pending.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setPending(null);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employees" />

            <PageHeader
                eyebrow="Records"
                title="Employees"
                description="The masterlist the yearly tally sheet is drawn from. Someone who leaves the city government is deactivated, not removed, so the sheets they already appear on keep reading correctly."
                actions={
                    <Button asChild>
                        <Link href={route('employees.create')}>
                            <UserPlus aria-hidden="true" />
                            Add employee
                        </Link>
                    </Button>
                }
            />

            <section className="bg-card overflow-hidden rounded-xl border">
                <div className="flex flex-col gap-3 border-b p-4 lg:flex-row lg:items-end">
                    <div className="grid flex-1 gap-1.5">
                        <Label htmlFor="search">Search</Label>
                        <div className="relative">
                            <Search
                                aria-hidden="true"
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            />
                            <Input
                                id="search"
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Surname, first name, position or employee no."
                                className="pl-9"
                                autoComplete="off"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5 lg:w-56">
                        <Label htmlFor="office">Office</Label>
                        <Select
                            value={filters.office || ALL}
                            onValueChange={(value) => applyFilters({ office: value === ALL ? '' : value, letter: '' })}
                        >
                            <SelectTrigger id="office" className="cursor-pointer">
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
                        <Label htmlFor="status">Status</Label>
                        <Select
                            value={filters.status || 'active'}
                            onValueChange={(value) => applyFilters({ status: value === 'active' ? '' : value })}
                        >
                            <SelectTrigger id="status" className="cursor-pointer">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">On the list</SelectItem>
                                <SelectItem value="inactive">Deactivated</SelectItem>
                                <SelectItem value={ALL}>Everyone</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters} className="cursor-pointer lg:mb-px">
                            <X aria-hidden="true" />
                            Clear
                        </Button>
                    )}
                </div>

                <div className="border-b px-4 py-3">
                    <LetterTabs available={letters} value={filters.letter} onChange={(letter) => applyFilters({ letter })} />
                </div>

                {employees.data.length === 0 ? (
                    hasFilters ? (
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
                            description="Add the employees this office keeps leave records for. You can encode leave for them once they are on the list."
                            action={
                                <Button asChild>
                                    <Link href={route('employees.create')}>
                                        <UserPlus aria-hidden="true" />
                                        Add employee
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
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
                                    className="hover:bg-accent/40 border-b transition-colors last:border-0"
                                >
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
                                                {!employee.isActive && (
                                                    <Badge variant="muted" className="shrink-0">
                                                        Deactivated
                                                    </Badge>
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
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-8 cursor-pointer">
                                                    <Ellipsis aria-hidden="true" />
                                                    <span className="sr-only">Actions for {employee.listName}</span>
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-60">
                                                <DropdownMenuItem asChild>
                                                    <Link href={route('employees.edit', employee.id)} className="cursor-pointer">
                                                        <Pencil aria-hidden="true" />
                                                        Edit record
                                                    </Link>
                                                </DropdownMenuItem>

                                                {canDeactivate &&
                                                    (employee.isActive ? (
                                                        <DropdownMenuItem onSelect={() => setPending(employee)} className="cursor-pointer">
                                                            <UserRoundX aria-hidden="true" />
                                                            Deactivate
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem onSelect={() => reactivate(employee)} className="cursor-pointer">
                                                            <UserRoundCheck aria-hidden="true" />
                                                            Put back on the list
                                                        </DropdownMenuItem>
                                                    ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </motion.tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <DataPagination meta={employees.meta} links={employees.links} label="employees" />
            </section>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                processing={processing}
                title="Take this employee off the list?"
                confirmLabel="Deactivate"
                onConfirm={confirmDeactivate}
                description={
                    <>
                        <p>
                            <span className="text-foreground font-medium">{pending?.fullName}</span> will no longer appear when encoding leave or on
                            this year&apos;s tally sheet.
                        </p>
                        <p>
                            {pending?.leaveRecordsCount
                                ? `The ${pending.leaveRecordsCount} leave record${pending.leaveRecordsCount === 1 ? '' : 's'} already encoded for them stay exactly as they are, and you can put them back on the list at any time.`
                                : 'Nothing is deleted, and you can put them back on the list at any time.'}
                        </p>
                    </>
                }
            />
        </AppLayout>
    );
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
