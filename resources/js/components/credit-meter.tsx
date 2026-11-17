import { cn } from '@/lib/utils';

interface CreditMeterProps {
    /** Days granted; null when there is no figure, which draws an empty track. */
    credits: number | null;
    /** Days taken, from the tally. */
    used: number;
    isOverdrawn: boolean;
    className?: string;
}

/**
 * A hairline showing how much of a grant has been taken.
 *
 * It repeats what the numbers beside it already say, so it is hidden from
 * screen readers; its job is to let HR spot the nearly-used and the overdrawn
 * down a column of 25 people without reading every figure. It fills from the
 * left with a transform, never a width, and the transition is dropped for
 * anyone who has asked for reduced motion.
 */
export function CreditMeter({ credits, used, isOverdrawn, className }: CreditMeterProps) {
    const share = credits === null ? 0 : credits > 0 ? Math.min(used / credits, 1) : used > 0 ? 1 : 0;

    const tone = isOverdrawn ? 'bg-destructive' : share >= 0.8 ? 'bg-warning' : 'bg-primary/70';

    return (
        <div aria-hidden="true" className={cn('bg-border/70 h-[3px] w-full overflow-hidden rounded-full', className)}>
            <div
                className={cn(
                    'h-full w-full origin-left rounded-full transition-transform duration-300 ease-out motion-reduce:transition-none',
                    tone,
                )}
                style={{ transform: `scaleX(${share})` }}
            />
        </div>
    );
}
