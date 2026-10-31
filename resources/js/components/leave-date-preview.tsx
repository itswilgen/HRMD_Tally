import { type LeavePreviewState } from '@/hooks/use-leave-preview';
import { monthLabel } from '@/lib/months';
import { cn } from '@/lib/utils';
import { type LeavePreview } from '@/types';
import { AlertTriangle, CalendarCheck, CalendarOff } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';

interface LeaveDatePreviewProps {
    state: LeavePreviewState;
    className?: string;
}

/**
 * What the save is about to write, before it writes it.
 *
 * The encoder is copying a paper form, so the question they actually have is
 * "did it understand my dates?" — not "is the form valid". Every number here
 * comes from LeaveEncodingService, the same code that does the saving.
 */
export function LeaveDatePreview({ state, className }: LeaveDatePreviewProps) {
    const reduceMotion = useReducedMotion();
    const { result, loading, failed } = state;

    return (
        <section aria-live="polite" aria-label="What will be saved" className={cn('bg-muted/40 rounded-lg border p-3', className)}>
            {result === null && !loading && (
                <p className="text-muted-foreground text-xs">
                    {failed
                        ? 'Could not check these dates. You can still save — the server checks again.'
                        : 'Choose an employee and the dates to see exactly what will be saved.'}
                </p>
            )}

            {loading && result === null && (
                <div className="space-y-2" aria-hidden="true">
                    <div className="bg-muted h-3 w-24 animate-pulse rounded" />
                    <div className="bg-muted h-5 w-full animate-pulse rounded" />
                </div>
            )}

            <AnimatePresence initial={false}>
                {result !== null && (
                    <motion.div
                        key="result"
                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={reduceMotion ? { duration: 0 } : { duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
                        className={cn('space-y-3', loading && 'opacity-60')}
                    >
                        <PreviewBody result={result} />
                    </motion.div>
                )}
            </AnimatePresence>
        </section>
    );
}

function PreviewBody({ result }: { result: LeavePreview }) {
    const hasConflicts = result.conflicts.length > 0;
    const nothingToSave = result.dates.length === 0;

    return (
        <>
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-muted-foreground text-2xs font-semibold tracking-[0.06em] uppercase">Will be saved</p>
                <p className="font-mono text-sm font-semibold" data-numeric>
                    {formatDays(result.days)} {result.days === 1 ? 'day' : 'days'}
                </p>
            </div>

            {nothingToSave ? (
                <p className="text-muted-foreground flex items-start gap-2 text-xs">
                    <CalendarOff aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                    <span>Every day in this range is a weekend or a holiday, so there is nothing to record.</span>
                </p>
            ) : (
                <ul className="flex flex-wrap gap-1">
                    {result.dates.map((date) => (
                        <li
                            key={date.date}
                            className="bg-card text-foreground rounded border px-1.5 py-0.5 font-mono text-xs"
                            title={`${date.weekday} ${date.day} ${monthLabel(date.month)}`}
                            data-numeric
                        >
                            {date.day} <span className="text-muted-foreground">{monthLabel(date.month)}</span>
                        </li>
                    ))}
                </ul>
            )}

            {result.skipped.length > 0 && (
                <div className="border-t pt-2">
                    <p className="text-muted-foreground flex items-start gap-2 text-xs">
                        <CalendarCheck aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            Skipped:{' '}
                            {result.skipped.map((skipped, index) => (
                                <span key={skipped.date}>
                                    {index > 0 && '; '}
                                    <span className="font-mono" data-numeric>
                                        {skipped.day}
                                    </span>{' '}
                                    {skipped.reason}
                                </span>
                            ))}
                        </span>
                    </p>
                </div>
            )}

            {hasConflicts && (
                <div className="bg-destructive-surface text-destructive rounded-md p-2.5">
                    <p className="flex items-center gap-2 text-xs font-semibold">
                        <AlertTriangle aria-hidden="true" className="size-3.5 shrink-0" />
                        Already encoded — nothing will be saved
                    </p>
                    <ul className="mt-1 space-y-0.5 pl-5 text-xs">
                        {result.conflicts.map((conflict) => (
                            <li key={conflict.date}>{conflict.reason}</li>
                        ))}
                    </ul>
                    <p className="mt-1.5 pl-5 text-xs">Open that day in the tally to change it instead.</p>
                </div>
            )}
        </>
    );
}

/** 2.5, not 2.50 — the same shape the tally prints. */
function formatDays(days: number): string {
    return Number.isInteger(days) ? String(days) : days.toFixed(1);
}
