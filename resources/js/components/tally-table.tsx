import { TallyMonthCell, type MonthState } from '@/components/tally-month-cell';
import { MONTH_LABELS } from '@/lib/months';
import { cn } from '@/lib/utils';
import { type EmployeeRecord, type LeaveTypeColumn, type TallyEntry, type TallyRow } from '@/types';
import { motion, useReducedMotion } from 'motion/react';
import { useRef, useState } from 'react';

interface TallyTableProps {
    employees: EmployeeRecord[];
    /** Keyed by employee id, from LeaveTallyService. */
    tally: Record<string, TallyRow>;
    leaveTypes: LeaveTypeColumn[];
    year: number;
    /** Null when the sheet on screen is not this year: there is no "now" on it. */
    currentMonth: number | null;
    /** meta.from, so the row numbers carry on across pages. */
    firstRowNumber: number;
    canSeeSalary: boolean;
    canEncode: boolean;
    compact: boolean;
    onAdd: (employee: EmployeeRecord, month: number) => void;
    onOpenEntry: (employee: EmployeeRecord, month: number, entry: TallyEntry) => void;
}

const EMPTY_ROW: TallyRow = {
    months: MONTH_LABELS.map((_, index) => ({ month: index + 1, entries: [], days: 0, daysText: '' })),
    totals: {},
    totalsText: {},
    grandTotal: 0,
    grandTotalText: '',
};

/** Header cells, shared by both header rows. */
const HEAD = 'bg-table-head text-muted-foreground border-b border-r text-2xs font-semibold tracking-[0.04em] uppercase';

/**
 * The sheet itself: employees down, January to December across.
 *
 * Two things are pinned, because they are what you read a row against: the two
 * header rows and the number + name columns. Everything else scrolls under
 * them inside the card, so the page behind never scrolls sideways.
 */
export function TallyTable({
    employees,
    tally,
    leaveTypes,
    year,
    currentMonth,
    firstRowNumber,
    canSeeSalary,
    canEncode,
    compact,
    onAdd,
    onOpenEntry,
}: TallyTableProps) {
    const reduceMotion = useReducedMotion();
    const scroller = useRef<HTMLDivElement>(null);
    const [scrolled, setScrolled] = useState(false);

    const cellPadding = compact ? 'px-2 py-1' : 'px-2.5 py-2';
    const stickyShadow = scrolled ? 'sticky-edge' : '';

    const monthState = (month: number): MonthState => {
        if (currentMonth === null) {
            return 'past';
        }

        if (month === currentMonth) {
            return 'current';
        }

        return month > currentMonth ? 'future' : 'past';
    };

    return (
        <div
            ref={scroller}
            onScroll={(event) => setScrolled(event.currentTarget.scrollLeft > 0)}
            // isolate keeps the sticky headers' z-indexes inside this box, so
            // they never paint over the pinned top bar and page header.
            className="isolate max-h-[68vh] overflow-auto overscroll-x-contain"
        >
            <table className="w-full border-separate border-spacing-0 text-left">
                <caption className="sr-only">
                    Leave tally sheet for {year}: one row per employee, one column per month, with yearly totals per leave code.
                </caption>

                <thead>
                    <tr>
                        <th scope="col" rowSpan={2} className={cn(HEAD, 'sticky top-0 left-0 z-30 h-9 w-10 px-2 text-center', stickyShadow)}>
                            No.
                        </th>
                        <th scope="col" rowSpan={2} className={cn(HEAD, 'sticky top-0 left-10 z-30 h-9 w-44 px-2.5 sm:w-56', stickyShadow)}>
                            Employee
                        </th>
                        <th scope="col" rowSpan={2} className={cn(HEAD, 'sticky top-0 z-20 hidden h-9 min-w-44 px-2.5 lg:table-cell')}>
                            Position title
                        </th>
                        <th scope="col" rowSpan={2} className={cn(HEAD, 'sticky top-0 z-20 hidden h-9 w-12 px-2 text-right xl:table-cell')}>
                            SG
                        </th>
                        {canSeeSalary && (
                            <th scope="col" rowSpan={2} className={cn(HEAD, 'sticky top-0 z-20 hidden h-9 w-28 px-2.5 text-right xl:table-cell')}>
                                Rate
                            </th>
                        )}

                        <th scope="colgroup" colSpan={12} className={cn(HEAD, 'bg-table-group-head sticky top-0 z-20 h-9 px-2.5 text-center')}>
                            Month — days + leave code
                        </th>
                        <th
                            scope="colgroup"
                            colSpan={leaveTypes.length + 1}
                            className={cn(HEAD, 'bg-table-group-head sticky top-0 z-20 h-9 px-2.5 text-center')}
                        >
                            Total days ({year})
                        </th>
                    </tr>

                    <tr>
                        {MONTH_LABELS.map((label, index) => (
                            <th
                                key={label}
                                scope="col"
                                aria-current={index + 1 === currentMonth ? 'date' : undefined}
                                className={cn(
                                    HEAD,
                                    'sticky top-9 z-20 h-8 w-28 min-w-28 px-2.5 text-center',
                                    index + 1 === currentMonth && 'bg-primary text-primary-foreground',
                                )}
                            >
                                {label}
                            </th>
                        ))}

                        {leaveTypes.map((type) => (
                            <th key={type.id} scope="col" title={type.name} className={cn(HEAD, 'sticky top-9 z-20 h-8 w-14 px-1 text-center')}>
                                {type.code}
                            </th>
                        ))}

                        <th scope="col" className={cn(HEAD, 'bg-total-grand sticky top-9 z-20 h-8 w-16 px-1 text-center')}>
                            All
                        </th>
                    </tr>
                </thead>

                <tbody>
                    {employees.map((employee, index) => {
                        const row = tally[String(employee.id)] ?? EMPTY_ROW;
                        const zebra = index % 2 === 1 ? 'bg-row-zebra' : 'bg-card';

                        return (
                            <motion.tr
                                key={employee.id}
                                initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={
                                    reduceMotion ? { duration: 0 } : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: [0.22, 1, 0.36, 1] }
                                }
                                className={cn('group/row hover:bg-row-hover', zebra)}
                            >
                                {/*
                                  The pinned cells carry the row's own surface:
                                  a transparent sticky cell would let the months
                                  scroll visibly underneath the names.
                                */}
                                <td
                                    className={cn(
                                        'text-muted-foreground group-hover/row:bg-row-hover sticky left-0 z-10 border-r border-b px-2 text-center font-mono text-xs',
                                        cellPadding,
                                        zebra,
                                        stickyShadow,
                                    )}
                                    data-numeric
                                >
                                    {firstRowNumber + index}
                                </td>

                                <td
                                    className={cn(
                                        'group-hover/row:bg-row-hover sticky left-10 z-10 border-r border-b px-2.5',
                                        cellPadding,
                                        zebra,
                                        stickyShadow,
                                    )}
                                >
                                    <div className="w-40 sm:w-52">
                                        <div className="flex items-center gap-1.5">
                                            <span className="truncate font-semibold">{employee.listName}</span>
                                            {!employee.isActive && (
                                                <span className="text-muted-foreground text-2xs shrink-0" title="No longer on the encoding list">
                                                    (out)
                                                </span>
                                            )}
                                        </div>
                                        {!compact && <p className="text-muted-foreground truncate text-xs">{employee.officeName ?? '—'}</p>}
                                    </div>
                                </td>

                                <td className={cn('hidden border-r border-b px-2.5 whitespace-nowrap lg:table-cell', cellPadding)}>
                                    {employee.positionTitle}
                                </td>

                                <td
                                    className={cn('hidden border-r border-b px-2 text-right font-mono text-xs xl:table-cell', cellPadding)}
                                    data-numeric
                                >
                                    {employee.salaryGrade ?? '–'}
                                </td>

                                {canSeeSalary && (
                                    <td
                                        className={cn('hidden border-r border-b px-2.5 text-right font-mono text-xs xl:table-cell', cellPadding)}
                                        data-numeric
                                    >
                                        {formatRate(employee.monthlyRate)}
                                    </td>
                                )}

                                {row.months.map((month) => (
                                    <TallyMonthCell
                                        key={month.month}
                                        employeeName={employee.listName}
                                        monthLabel={MONTH_LABELS[month.month - 1]}
                                        month={month}
                                        state={monthState(month.month)}
                                        canEncode={canEncode}
                                        compact={compact}
                                        onAdd={() => onAdd(employee, month.month)}
                                        onOpenEntry={(entry) => onOpenEntry(employee, month.month, entry)}
                                    />
                                ))}

                                {leaveTypes.map((type) => (
                                    <td
                                        key={type.id}
                                        className={cn('border-r border-b px-1 text-center font-mono text-xs', cellPadding)}
                                        data-numeric
                                    >
                                        {row.totalsText[String(type.id)] || <span className="text-muted-foreground">–</span>}
                                    </td>
                                ))}

                                <td
                                    className={cn('bg-total-grand border-b px-1 text-center font-mono text-xs font-semibold', cellPadding)}
                                    data-numeric
                                >
                                    {row.grandTotalText || <span className="text-muted-foreground font-normal">–</span>}
                                </td>
                            </motion.tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/**
 * The rate arrives as a decimal string so the centavos stay exact; it is only
 * grouped for reading here, never recomputed.
 */
function formatRate(rate: string | null | undefined): string {
    if (rate === null || rate === undefined || rate === '') {
        return '–';
    }

    const value = Number(rate);

    return Number.isNaN(value) ? rate : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
