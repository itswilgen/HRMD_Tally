import { LeaveChip } from '@/components/leave-chip';
import { type LeaveTypeColumn } from '@/types';

/**
 * What the codes and colours on the sheet mean.
 *
 * Printed above the sheet rather than hidden in a tooltip: a new encoder reads
 * this once and then never has to guess what MFL is.
 */
export function LeaveLegend({ leaveTypes }: { leaveTypes: LeaveTypeColumn[] }) {
    if (leaveTypes.length === 0) {
        return null;
    }

    return (
        <div className="border-border/60 bg-muted/35 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2 text-xs shadow-2xs">
            <div className="text-foreground/80 flex items-center gap-1.5 font-semibold">
                <span className="bg-primary size-1.5 rounded-full" />
                <span className="text-2xs text-muted-foreground tracking-wider uppercase">Legend</span>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                {leaveTypes.map((type) => (
                    <LeaveChip key={type.id} color={type.color} code={type.code} name={type.name} />
                ))}
            </div>

            <span className="text-2xs text-muted-foreground ml-auto font-medium">AM / PM = half day (0.5)</span>
        </div>
    );
}
