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
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span className="text-muted-foreground text-2xs font-semibold tracking-[0.08em] uppercase">Codes</span>

            {leaveTypes.map((type) => (
                <LeaveChip key={type.id} color={type.color} code={type.code} name={type.name} />
            ))}

            <span className="text-muted-foreground text-xs">· AM / PM = half day</span>
        </div>
    );
}
