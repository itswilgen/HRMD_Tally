import { cn } from '@/lib/utils';

/**
 * The chip palette, keyed by `leave_types.color`.
 *
 * The class pairs are written out instead of built from the key because
 * Tailwind only generates a class it can read in the source. A key HR has
 * not been offered yet falls back to the neutral pair rather than rendering
 * an invisible chip.
 */
const CHIP_TOKENS: Record<string, string> = {
    sky: 'bg-chip-sky text-chip-sky-ink',
    amber: 'bg-chip-amber text-chip-amber-ink',
    violet: 'bg-chip-violet text-chip-violet-ink',
    teal: 'bg-chip-teal text-chip-teal-ink',
    slate: 'bg-chip-slate text-chip-slate-ink',
    emerald: 'bg-chip-emerald text-chip-emerald-ink',
    rose: 'bg-chip-rose text-chip-rose-ink',
};

export function chipTokens(color: string): string {
    return CHIP_TOKENS[color] ?? 'bg-secondary text-secondary-foreground';
}

interface LeaveChipProps {
    color: string;
    /** The leave code, e.g. "SL". */
    code: string;
    /**
     * Day figures as the sheet writes them — "23–25", "3, 6–7", "13 PM".
     * Built by LeaveTallyService; never assembled here.
     */
    figures?: string;
    /** The leave type's full name, used in the legend. */
    name?: string;
    /** Given only when the chip opens the days behind it. */
    onClick?: () => void;
    title?: string;
    label?: string;
    className?: string;
}

/**
 * One leave entry as it reads on the sheet: the days in mono, then the code.
 *
 * Colour is the only decoration in this interface, and it is doing work —
 * telling six leave types apart at a glance in a grid of 300 cells.
 */
export function LeaveChip({ color, code, figures, name, onClick, title, label, className }: LeaveChipProps) {
    const isInteractive = onClick !== undefined;
    const shape = cn(
        'text-2xs inline-flex max-w-full items-center gap-1 rounded-md px-1.5 py-0.5 leading-tight shadow-[0_1px_2px_rgba(0,0,0,0.04)] ring-1 ring-current/15 ring-inset',
        isInteractive && 'cursor-pointer transition-all hover:shadow-xs hover:brightness-95 active:scale-[0.97]',
        chipTokens(color),
        className,
    );

    const content = (
        <>
            {figures !== undefined && (
                <span className="font-mono font-semibold tracking-tight" data-numeric>
                    {figures}
                </span>
            )}
            <span className={cn('font-bold', figures === undefined && 'font-mono')}>{code}</span>
            {name !== undefined && <span className="truncate font-normal opacity-85">{name}</span>}
        </>
    );

    if (onClick === undefined) {
        return (
            <span className={shape} title={title}>
                {content}
            </span>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            aria-label={label}
            className={cn(
                shape,
                'focus-visible:ring-ring cursor-pointer transition-transform focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none',
                'hover:-translate-y-px active:translate-y-0',
            )}
        >
            {content}
        </button>
    );
}
