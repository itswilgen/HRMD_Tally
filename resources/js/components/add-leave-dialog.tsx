import { EmployeeCombobox } from '@/components/employee-combobox';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { LeaveDatePreview } from '@/components/leave-date-preview';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useLeavePreview } from '@/hooks/use-leave-preview';
import { monthLabel } from '@/lib/months';
import { type DayPartOption, type DayPartValue, type EmployeeRecord, type LeaveTypeOption } from '@/types';
import { useForm } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { type FormEventHandler, useEffect } from 'react';

/**
 * The segmented control's wording.
 *
 * Short, because three buttons share one row; the enum's own longer label
 * ("Afternoon (PM)") stays as the accessible name.
 */
const DAY_PART_SHORT: Record<string, string> = {
    WHOLE: 'Whole day',
    AM: 'AM',
    PM: 'PM',
};

/**
 * The date a month's "+" starts the encoder on.
 *
 * The first working day of the month rather than the 1st: four of 2026's
 * months begin on a weekend, and opening on one of those greeted the encoder
 * with "nothing to record" before they had typed anything. Holidays are the
 * server's knowledge, so a 1 January still opens skipped — but the preview
 * says which holiday it is, which is the one case worth explaining.
 */
function firstWorkdayOf(year: number, month: number): string {
    const date = new Date(year, month - 1, 1);

    while (date.getDay() === 0 || date.getDay() === 6) {
        date.setDate(date.getDate() + 1);
    }

    return `${year}-${String(month).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Where the dialog was opened from: a row's "+", or the page header. */
export interface AddLeaveTarget {
    employeeId: number | null;
    month: number | null;
}

interface AddLeaveDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The employees on the sheet, which is who the picker can offer. */
    employees: EmployeeRecord[];
    leaveTypes: LeaveTypeOption[];
    dayParts: DayPartOption[];
    year: number;
    target: AddLeaveTarget;
}

/**
 * Encoding one approved leave form.
 *
 * A card over the sheet rather than a drawer beside it. Encoding is the one
 * thing being done while it is open — the row underneath is already named in
 * the heading — so the form belongs in the middle of the screen, where the
 * eye already is after clicking a cell, instead of at the far right edge.
 *
 * The dialog decides nothing itself: the dates it promises come back from
 * LeaveEncodingService, the same service that does the saving, so it cannot
 * offer a range the save would refuse.
 */
export function AddLeaveDialog({ open, onOpenChange, employees, leaveTypes, dayParts, year, target }: AddLeaveDialogProps) {
    const { data, setData, errors, processing, post, clearErrors } = useForm({
        employee_id: '',
        leave_type_id: '',
        date_from: '',
        date_to: '',
        day_part: 'WHOLE' as DayPartValue,
        remarks: '',
    });

    // Opening the dialog is what fills it in: whoever's "+" was clicked and the
    // month it sat in, with the day left for the encoder to type. Re-running on
    // every render would fight their typing, so only the target matters here.
    useEffect(() => {
        if (!open) {
            return;
        }

        const openOn = target.month === null ? '' : firstWorkdayOf(year, target.month);
        const firstType = leaveTypes[0];

        clearErrors();
        setData({
            employee_id: target.employeeId === null ? '' : String(target.employeeId),
            leave_type_id: firstType === undefined ? '' : String(firstType.value),
            date_from: openOn,
            date_to: openOn,
            day_part: 'WHOLE',
            remarks: '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, target.employeeId, target.month, year]);

    const isHalfDay = data.day_part !== 'WHOLE';

    const preview = useLeavePreview({
        employeeId: data.employee_id,
        dateFrom: data.date_from,
        dateTo: isHalfDay ? data.date_from : data.date_to,
        dayPart: data.day_part,
        enabled: open,
    });

    // Only a preview that came back can block the button: if the check could
    // not run, saving is still the right thing to try.
    const blocked = preview.result !== null && (preview.result.conflicts.length > 0 || preview.result.dates.length === 0);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('leave.store'), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const monthHint = target.month === null ? null : `${monthLabel(target.month)} ${year}`;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                // Tall forms on short laptops scroll inside the card; the 1rem
                // inset keeps it off the edges of a phone.
                className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl gap-0 overflow-y-auto rounded-xl sm:rounded-xl"
            >
                <DialogHeader className="space-y-2 pr-8 text-left">
                    <div className="flex items-center gap-3">
                        <div className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-xl">
                            <CalendarPlus className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-lg font-bold tracking-tight">Add leave</DialogTitle>
                            <p className="text-2xs text-primary font-semibold tracking-wider uppercase">Encoding Form</p>
                        </div>
                    </div>
                    <DialogDescription className="text-muted-foreground text-xs leading-relaxed">
                        One entry per approved leave form{monthHint === null ? '' : `, opened on ${monthHint}`}. Weekends and holidays inside a range
                        are skipped automatically.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="mt-6 grid gap-4">
                    <FormField id="employee" label="Employee" error={errors.employee_id}>
                        <EmployeeCombobox
                            id="employee"
                            employees={employees}
                            value={data.employee_id === '' ? null : Number(data.employee_id)}
                            onChange={(employeeId) => setData('employee_id', employeeId === null ? '' : String(employeeId))}
                            invalid={errors.employee_id !== undefined}
                        />
                    </FormField>

                    <FormField id="leave_type" label="Leave type" error={errors.leave_type_id}>
                        <Select value={data.leave_type_id} onValueChange={(value) => setData('leave_type_id', value)}>
                            <SelectTrigger id="leave_type" className="h-11 cursor-pointer">
                                <SelectValue placeholder="Choose a leave type" />
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

                    <div>
                        <span id="day_part_label" className="text-sm leading-none font-medium">
                            Whole day or half day
                        </span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            aria-labelledby="day_part_label"
                            value={data.day_part}
                            onValueChange={(value) => {
                                // Radix sends '' when the pressed item is
                                // deselected; a leave is always one of the
                                // three, so that is ignored.
                                if (value === '') {
                                    return;
                                }

                                setData((current) => ({
                                    ...current,
                                    day_part: value as DayPartValue,
                                    date_to: value === 'WHOLE' ? current.date_to : current.date_from,
                                }));
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

                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField id="date_from" label={isHalfDay ? 'Date' : 'First day'} error={errors.date_from}>
                            <Input
                                id="date_from"
                                type="date"
                                className="h-11 cursor-pointer"
                                value={data.date_from}
                                onChange={(event) => {
                                    const value = event.target.value;

                                    setData((current) => ({
                                        ...current,
                                        date_from: value,
                                        // One day is the common case, so the
                                        // last day follows the first until the
                                        // encoder moves it themselves.
                                        date_to: current.date_to === '' || current.date_to < value ? value : current.date_to,
                                    }));
                                }}
                            />
                        </FormField>

                        <FormField id="date_to" label="Last day" error={errors.date_to} hint={isHalfDay ? 'A half day covers one date.' : undefined}>
                            <Input
                                id="date_to"
                                type="date"
                                className="h-11 cursor-pointer"
                                value={isHalfDay ? data.date_from : data.date_to}
                                min={data.date_from === '' ? undefined : data.date_from}
                                disabled={isHalfDay}
                                onChange={(event) => setData('date_to', event.target.value)}
                            />
                        </FormField>
                    </div>

                    <LeaveDatePreview state={preview} />

                    <FormField id="remarks" label="Remarks" optional error={errors.remarks}>
                        <Textarea
                            id="remarks"
                            value={data.remarks}
                            onChange={(event) => setData('remarks', event.target.value)}
                            placeholder="e.g. Approved leave form no. 2026-0481"
                            rows={2}
                            maxLength={255}
                        />
                    </FormField>

                    <DialogFooter className="mt-2 gap-2 sm:gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} className="h-11 cursor-pointer">
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || blocked} className="h-11 cursor-pointer">
                            {processing ? 'Saving…' : 'Save leave'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
