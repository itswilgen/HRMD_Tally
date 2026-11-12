import { CreditMeter } from '@/components/credit-meter';
import { LeaveChip } from '@/components/leave-chip';
import { creditInputValue, looksLikeFigure } from '@/lib/credits';
import { cn } from '@/lib/utils';
import { type CreditCell, type CreditColumn, type CreditRow, type EmployeeRecord } from '@/types';
import { Link } from '@inertiajs/react';
import { type KeyboardEvent, useRef, useState } from 'react';

/** Unsaved figures: employee id => leave type id => what was typed. */
export type CreditDrafts = Record<string, Record<string, string>>;

interface CreditsGridProps {
    employees: EmployeeRecord[];
    /** Keyed by employee id, from LeaveCreditService. */
    credits: Record<string, CreditRow>;
    columns: CreditColumn[];
    year: number;
    firstRowNumber: number;
    canManage: boolean;
    drafts: CreditDrafts;
    /** Server messages for cells, keyed "employeeId:leaveTypeId". */
    errors: Record<string, string>;
    disabled: boolean;
    /** null puts the cell back to the figure on file. */
    onChange: (employeeId: number, leaveTypeId: number, value: string | null) => void;
}

const HEAD = 'bg-table-head text-muted-foreground border-b border-r text-2xs font-semibold tracking-[0.04em] uppercase';

/**
 * The year's credits: employees down, leave codes across.
 *
 * For an HR Admin every figure is an input, and the grid behaves like the
 * paper pile it replaces — HR fills one leave code down the whole page, so
 * Enter (and the arrow keys) move down the column rather than submitting, Esc
 * puts a cell back, and nothing is sent until "Save changes".
 *
 * Every cell also says what was taken and what is left, and a hairline under
 * it shows how far into the grant the employee is. None of those figures is
 * worked out here: they come from LeaveCreditService, which takes "taken" from
 * the tally, so this screen and the tally sheet cannot disagree.
 */
export function CreditsGrid({ employees, credits, columns, year, firstRowNumber, canManage, drafts, errors, disabled, onChange }: CreditsGridProps) {
    const table = useRef<HTMLTableElement>(null);
    const [scrolled, setScrolled] = useState(false);
    const stickyShadow = scrolled ? 'sticky-edge' : '';

    const focusCell = (row: number, column: number) => {
        table.current?.querySelector<HTMLInputElement>(`input[data-cell="${row}:${column}"]:not(:disabled)`)?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>, row: number, column: number, employeeId: number, typeId: number) => {
        if (event.key === 'Enter' || event.key === 'ArrowDown') {
            event.preventDefault();
            focusCell(event.shiftKey && event.key === 'Enter' ? row - 1 : row + 1, column);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            focusCell(row - 1, column);
        } else if (event.key === 'Escape') {
            onChange(employeeId, typeId, null);
        }
    };

    return (
        <div
            onScroll={(event) => setScrolled(event.currentTarget.scrollLeft > 0)}
            className="isolate max-h-[68vh] overflow-auto overscroll-x-contain"
        >
            <table ref={table} className="w-full border-separate border-spacing-0 text-left">
                <caption className="sr-only">
                    Leave credits for {year}: one row per employee, one column per leave code, each showing the days granted, taken and left.
                </caption>

                <thead>
                    <tr>
                        <th scope="col" className={cn(HEAD, 'sticky top-0 left-0 z-30 h-10 w-10 px-2 text-center', stickyShadow)}>
                            No.
                        </th>
                        <th scope="col" className={cn(HEAD, 'sticky top-0 left-10 z-30 h-10 w-44 px-2.5 sm:w-56', stickyShadow)}>
                            Employee
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.id}
                                scope="col"
                                title={
                                    column.isActive
                                        ? column.name
                                        : `${column.name} — retired: existing figures can be corrected, new ones cannot be added`
                                }
                                className={cn(HEAD, 'sticky top-0 z-20 h-10 min-w-28 px-2 text-center normal-case')}
                            >
                                <span className="inline-flex items-center gap-1">
                                    <LeaveChip color={column.color} code={column.code} />
                                    {!column.isActive && <span className="text-2xs text-muted-foreground font-medium">retired</span>}
                                </span>
                            </th>
                        ))}
                        <th scope="col" className={cn(HEAD, 'bg-total-grand sticky top-0 z-20 h-10 min-w-28 px-2 text-center')}>
                            Total
                        </th>
                    </tr>
                </thead>

                <tbody>
                    {employees.map((employee, rowIndex) => {
                        const row = credits[String(employee.id)];
                        const zebra = rowIndex % 2 === 1 ? 'bg-row-zebra' : 'bg-card';

                        return (
                            <tr key={employee.id} className={cn('group/row hover:bg-row-hover', zebra)}>
                                <td
                                    className={cn(
                                        'text-muted-foreground group-hover/row:bg-row-hover sticky left-0 z-10 border-r border-b px-2 py-2 text-center font-mono text-xs',
                                        zebra,
                                        stickyShadow,
                                    )}
                                    data-numeric
                                >
                                    {firstRowNumber + rowIndex}
                                </td>

                                <td
                                    className={cn(
                                        'group-hover/row:bg-row-hover sticky left-10 z-10 border-r border-b px-2.5 py-2',
                                        zebra,
                                        stickyShadow,
                                    )}
                                >
                                    <div className="w-40 sm:w-52">
                                        <Link
                                            href={route('credits.show', { employee: employee.id, year })}
                                            className="hover:text-primary focus-visible:ring-ring block truncate rounded-sm font-semibold underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {employee.listName}
                                        </Link>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {employee.officeName ?? '—'}
                                            {!employee.isActive && ' · no longer active'}
                                        </p>
                                    </div>
                                </td>

                                {columns.map((column, columnIndex) => {
                                    const cell = row?.cells[String(column.id)];
                                    const draft = drafts[String(employee.id)]?.[String(column.id)];
                                    const error = errors[`${employee.id}:${column.id}`];

                                    return (
                                        <td key={column.id} className="border-r border-b px-2 py-1.5 align-top">
                                            {canManage ? (
                                                <EditableCell
                                                    cell={cell}
                                                    column={column}
                                                    employee={employee}
                                                    draft={draft}
                                                    error={error}
                                                    disabled={disabled}
                                                    dataCell={`${rowIndex}:${columnIndex}`}
                                                    onChange={(value) => onChange(employee.id, column.id, value)}
                                                    onKeyDown={(event) => onKeyDown(event, rowIndex, columnIndex, employee.id, column.id)}
                                                />
                                            ) : (
                                                <ReadOnlyCell cell={cell} />
                                            )}
                                        </td>
                                    );
                                })}

                                <td className="bg-total-grand border-b px-2 py-1.5 text-center align-top">
                                    {row?.hasCredits ? (
                                        <div className="space-y-0.5">
                                            <p
                                                className={cn('font-mono text-sm font-semibold', row.remainingTotal < 0 && 'text-destructive')}
                                                data-numeric
                                                title={`${row.creditsTotalText} granted − ${row.usedTotalText} taken`}
                                            >
                                                {row.remainingTotalText}
                                                <span className="text-muted-foreground text-2xs font-sans font-normal"> left</span>
                                            </p>
                                            <p className="text-muted-foreground text-2xs font-mono" data-numeric>
                                                {row.creditsTotalText} − {row.usedTotalText}
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-muted-foreground py-1 text-xs">
                                            {row && row.usedTotal > 0 ? `${row.usedTotalText} taken` : '—'}
                                        </p>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

interface EditableCellProps {
    cell: CreditCell | undefined;
    column: CreditColumn;
    employee: EmployeeRecord;
    draft: string | undefined;
    error: string | undefined;
    disabled: boolean;
    dataCell: string;
    onChange: (value: string | null) => void;
    onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => void;
}

function EditableCell({ cell, column, employee, draft, error, disabled, dataCell, onChange, onKeyDown }: EditableCellProps) {
    const saved = creditInputValue(cell);
    const value = draft ?? saved;
    const changed = draft !== undefined;
    const looksWrong = !looksLikeFigure(value);
    // A retired leave type takes corrections to a figure already on file and
    // nothing new, which is the same line the server draws.
    const locked = !column.isActive && cell?.credits === null;

    return (
        <div className="space-y-1">
            <input
                type="text"
                inputMode="decimal"
                autoComplete="off"
                data-cell={dataCell}
                value={value}
                disabled={disabled || locked}
                placeholder="—"
                onChange={(event) => onChange(event.target.value === saved ? null : event.target.value)}
                onKeyDown={onKeyDown}
                onFocus={(event) => event.currentTarget.select()}
                aria-label={`${column.code} credits for ${employee.listName}`}
                aria-invalid={error !== undefined || looksWrong ? true : undefined}
                title={error ?? (locked ? `${column.code} is retired: no new figure can be added` : undefined)}
                className={cn(
                    'border-input bg-card placeholder:text-muted-foreground/60 h-8 w-full rounded-md border px-2 text-center font-mono text-sm font-semibold tabular-nums transition-colors',
                    'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-2 focus-visible:outline-none',
                    'disabled:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-70',
                    changed && 'border-primary/50 bg-success-surface',
                    (error !== undefined || looksWrong) && 'border-destructive bg-destructive-surface focus-visible:ring-destructive/40',
                )}
            />
            <CellFooter cell={cell} />
        </div>
    );
}

function ReadOnlyCell({ cell }: { cell: CreditCell | undefined }) {
    return (
        <div className="space-y-1 text-center">
            <p className="h-8 font-mono text-sm leading-8 font-semibold" data-numeric title={cell?.credits === null ? 'No figure yet' : undefined}>
                {cell?.credits === null || cell === undefined ? <span className="text-muted-foreground/60">—</span> : cell.creditsText}
            </p>
            <CellFooter cell={cell} />
        </div>
    );
}

/** "4.5 taken · 10.5 left" and the meter, under every cell. */
function CellFooter({ cell }: { cell: CreditCell | undefined }) {
    if (cell === undefined) {
        return null;
    }

    return (
        <>
            <p className="text-muted-foreground text-2xs text-center font-mono whitespace-nowrap" data-numeric>
                {cell.used > 0 ? `${cell.usedText} taken` : 'none taken'}
                {cell.remaining !== null && (
                    <>
                        {' · '}
                        <span className={cn(cell.isOverdrawn ? 'text-destructive font-semibold' : 'text-foreground')}>
                            {cell.isOverdrawn ? `over by ${cell.remainingText.replace('-', '')}` : `${cell.remainingText} left`}
                        </span>
                    </>
                )}
            </p>
            <CreditMeter credits={cell.credits} used={cell.used} isOverdrawn={cell.isOverdrawn} />
        </>
    );
}
