import { LeaveChip } from '@/components/leave-chip';
import { cn } from '@/lib/utils';
import { type TallyEntry, type TallyMonth } from '@/types';
import { Plus } from 'lucide-react';

export type MonthState = 'past' | 'current' | 'future';

interface TallyMonthCellProps {
    /** Used in the aria-labels, which have to say who and when. */
    employeeName: string;
    monthLabel: string;
    month: TallyMonth;
    state: MonthState;
    canEncode: boolean;
    compact: boolean;
    onAdd: () => void;
    onOpenEntry: (entry: TallyEntry) => void;
}

const STATE_BACKGROUND: Record<MonthState, string> = {
    // Past months keep the row's own surface, so the sheet reads as rows
    // first and columns second.
    past: '',
    current: 'bg-month-current month-spine',
    future: 'bg-month-future',
};

/**
 * One month of one employee's year.
 *
 * Everything written here was written by LeaveTallyService: the figures, the
 * ranges and the half-day suffixes. The cell adds the two things only a screen
 * can add — a way in to the days behind a chip, and a way to add a day.
 */
export function TallyMonthCell({ employeeName, monthLabel, month, state, canEncode, compact, onAdd, onOpenEntry }: TallyMonthCellProps) {
    return (
        <td className={cn('group/cell relative border-r border-b align-top', compact ? 'px-2 py-1' : 'px-2.5 py-2', STATE_BACKGROUND[state])}>
            <div className={cn('flex min-h-5 flex-col items-start gap-1', canEncode && 'pr-6')}>
                {month.entries.map((entry) => (
                    <LeaveChip
                        key={entry.leaveTypeId}
                        color={entry.color}
                        code={entry.code}
                        figures={entry.dayFigures}
                        onClick={() => onOpenEntry(entry)}
                        label={`${entry.text} in ${monthLabel} for ${employeeName}. Open to edit or remove.`}
                        title={`${entry.daysText} day${entry.days === 1 ? '' : 's'}`}
                    />
                ))}
            </div>

            {canEncode && (
                <button
                    type="button"
                    onClick={onAdd}
                    aria-label={`Add leave for ${employeeName} in ${monthLabel}`}
                    className={cn(
                        'text-muted-foreground absolute right-1 bottom-1 grid size-6 cursor-pointer place-items-center rounded-sm border border-dashed',
                        'hover:border-primary hover:text-primary focus-visible:ring-ring transition-opacity focus-visible:ring-2 focus-visible:outline-none',
                        // Hidden until this cell is pointed at or tabbed to, so
                        // 300 dashed squares do not compete with the figures.
                        'opacity-0 group-hover/cell:opacity-100 focus-visible:opacity-100',
                    )}
                >
                    <Plus className="size-3.5" aria-hidden="true" />
                </button>
            )}
        </td>
    );
}
