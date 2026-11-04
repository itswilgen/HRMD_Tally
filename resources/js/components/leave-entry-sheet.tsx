import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { LeaveChip } from '@/components/leave-chip';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { type DayPartOption, type DayPartValue, type LeaveTypeOption, type TallyEntry, type TallyEntryDay } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { type FormEventHandler, useEffect, useState } from 'react';

/** Which chip is open, and whose it is. */
export interface LeaveEntryTarget {
    employeeName: string;
    monthLabel: string;
    entry: TallyEntry;
}

interface LeaveEntrySheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    target: LeaveEntryTarget | null;
    leaveTypes: LeaveTypeOption[];
    dayParts: DayPartOption[];
    year: number;
    canEdit: boolean;
    canDelete: boolean;
}

const DAY_PART_SHORT: Record<string, string> = {
    WHOLE: 'Whole day',
    AM: 'AM',
    PM: 'PM',
};

/**
 * The days behind one chip, each one correctable.
 *
 * A chip can stand for a dozen rows ("3, 6–7, 23–25"), and a correction is
 * always to one of those days — one date typed wrong on one leave form. So the
 * panel lists the days rather than the chip, and edits them one at a time.
 */
export function LeaveEntrySheet({ open, onOpenChange, target, leaveTypes, dayParts, year, canEdit, canDelete }: LeaveEntrySheetProps) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [removing, setRemoving] = useState<TallyEntryDay | null>(null);
    const [deleting, setDeleting] = useState(false);

    const { data, setData, errors, processing, patch, clearErrors } = useForm({
        leave_type_id: '',
        leave_date: '',
        day_part: 'WHOLE' as DayPartValue,
        remarks: '',
    });

    // Closing the panel ends any correction in progress: reopening another
    // chip must not inherit the last one's half-finished form.
    useEffect(() => {
        if (!open) {
            setEditingId(null);
            setRemoving(null);
        }
    }, [open]);

    if (target === null) {
        return null;
    }

    const { employeeName, monthLabel, entry } = target;

    const startEditing = (record: TallyEntryDay) => {
        clearErrors();
        setEditingId(record.id);
        setData({
            leave_type_id: String(entry.leaveTypeId),
            leave_date: record.date,
            day_part: record.dayPart,
            remarks: record.remarks ?? '',
        });
    };

    const save =
        (recordId: number): FormEventHandler =>
        (event) => {
            event.preventDefault();

            patch(route('leave.update', recordId), {
                preserveScroll: true,
                onSuccess: () => setEditingId(null),
            });
        };

    const confirmRemove = () => {
        if (removing === null) {
            return;
        }

        setDeleting(true);

        router.delete(route('leave.destroy', removing.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setRemoving(null);

                // The chip may have been the month's last entry, in which case
                // there is nothing left in this panel to look at.
                if (entry.records.length === 1) {
                    onOpenChange(false);
                }
            },
        });
    };

    return (
        <>
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent side="right" className="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-md">
                    <SheetHeader className="pr-12 text-left">
                        <SheetTitle className="flex items-center gap-2">
                            <LeaveChip color={entry.color} code={entry.code} figures={entry.dayFigures} />
                            <span className="sr-only">{entry.text}</span>
                            <span className="text-base font-semibold">
                                {monthLabel} {year}
                            </span>
                        </SheetTitle>
                        <SheetDescription>
                            {employeeName} — {entry.daysText} {entry.days === 1 ? 'day' : 'days'} across{' '}
                            {entry.records.length === 1 ? 'one entry' : `${entry.records.length} entries`}.
                        </SheetDescription>
                    </SheetHeader>

                    <ul className="mt-6 flex-1 space-y-2.5">
                        {entry.records.map((record) => (
                            <li key={record.id} className="border-border/70 bg-card rounded-xl border p-3.5 shadow-2xs transition-all">
                                {editingId === record.id ? (
                                    <form onSubmit={save(record.id)} className="grid gap-3">
                                        <FormField id={`type-${record.id}`} label="Leave type" error={errors.leave_type_id}>
                                            <Select value={data.leave_type_id} onValueChange={(value) => setData('leave_type_id', value)}>
                                                <SelectTrigger id={`type-${record.id}`} className="h-11 cursor-pointer">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {leaveTypes.map((type) => (
                                                        <SelectItem key={type.value} value={String(type.value)}>
                                                            {type.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </FormField>

                                        <FormField id={`date-${record.id}`} label="Date" error={errors.leave_date}>
                                            <Input
                                                id={`date-${record.id}`}
                                                type="date"
                                                className="h-11 cursor-pointer"
                                                value={data.leave_date}
                                                onChange={(event) => setData('leave_date', event.target.value)}
                                            />
                                        </FormField>

                                        <div>
                                            <span id={`part-label-${record.id}`} className="text-sm leading-none font-medium">
                                                Whole day or half day
                                            </span>
                                            <ToggleGroup
                                                type="single"
                                                variant="outline"
                                                aria-labelledby={`part-label-${record.id}`}
                                                value={data.day_part}
                                                onValueChange={(value) => {
                                                    if (value === '') {
                                                        return;
                                                    }

                                                    setData('day_part', value as DayPartValue);
                                                }}
                                                className="mt-1.5 grid grid-cols-3 gap-1.5"
                                            >
                                                {dayParts.map((part) => (
                                                    <ToggleGroupItem
                                                        key={part.value}
                                                        value={part.value}
                                                        aria-label={part.label}
                                                        className="h-11 cursor-pointer px-3 text-sm"
                                                    >
                                                        {DAY_PART_SHORT[part.value] ?? part.label}
                                                    </ToggleGroupItem>
                                                ))}
                                            </ToggleGroup>
                                            <InputError message={errors.day_part} className="mt-1.5" />
                                        </div>

                                        <FormField id={`remarks-${record.id}`} label="Remarks" optional error={errors.remarks}>
                                            <Textarea
                                                id={`remarks-${record.id}`}
                                                rows={2}
                                                maxLength={255}
                                                value={data.remarks}
                                                onChange={(event) => setData('remarks', event.target.value)}
                                            />
                                        </FormField>

                                        <div className="flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="h-10 cursor-pointer"
                                                onClick={() => setEditingId(null)}
                                                disabled={processing}
                                            >
                                                Cancel
                                            </Button>
                                            <Button type="submit" className="h-10 cursor-pointer" disabled={processing}>
                                                {processing ? 'Saving…' : 'Save change'}
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="font-mono text-sm font-semibold" data-numeric>
                                                {record.dayText} {monthLabel}
                                            </p>
                                            {/* Nothing where there are no remarks: a column of
                                                "No remarks" would be three lines of noise over
                                                the dates, which are what the encoder is reading. */}
                                            {record.remarks !== null && (
                                                <p className="text-muted-foreground mt-0.5 text-xs break-words">{record.remarks}</p>
                                            )}
                                        </div>

                                        {(canEdit || canDelete) && (
                                            <div className="flex shrink-0 gap-1">
                                                {canEdit && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-9 cursor-pointer"
                                                        aria-label={`Change ${record.dayText} ${monthLabel} for ${employeeName}`}
                                                        onClick={() => startEditing(record)}
                                                    >
                                                        <Pencil className="size-4" aria-hidden="true" />
                                                    </Button>
                                                )}
                                                {canDelete && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        // Red on hover, not at rest: a row of red
                                                        // bins on a screen used all day reads as a
                                                        // warning about data that is perfectly fine.
                                                        className="text-muted-foreground hover:text-destructive hover:bg-destructive-surface size-9 cursor-pointer"
                                                        aria-label={`Remove ${record.dayText} ${monthLabel} for ${employeeName}`}
                                                        onClick={() => setRemoving(record)}
                                                    >
                                                        <Trash2 className="size-4" aria-hidden="true" />
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>

                    <SheetFooter className="mt-6">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} className="h-11 cursor-pointer">
                            Close
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(next) => !next && setRemoving(null)}
                title="Remove this leave day?"
                description={
                    <>
                        <p>
                            {removing?.dayText} {monthLabel} {year} will be taken off {employeeName}'s tally, and the yearly totals will go down by{' '}
                            {removing?.dayPart === 'WHOLE' ? '1 day' : '0.5 day'}.
                        </p>
                        <p className="text-muted-foreground">
                            The record is kept and the log shows who removed it, so this can be undone by an admin.
                        </p>
                    </>
                }
                confirmLabel="Remove day"
                tone="destructive"
                processing={deleting}
                onConfirm={confirmRemove}
            />
        </>
    );
}
