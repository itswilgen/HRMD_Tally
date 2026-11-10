import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type EmployeeRecord } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2, OctagonAlert, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

interface EraseEmployeesDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The ticked rows of the deleted records list. */
    selected: EmployeeRecord[];
    /** Clears the tick-boxes once the records are gone. */
    onErased: () => void;
}

/**
 * Typed to confirm. A different word from the delete dialog's on purpose: the
 * muscle memory of typing DELETE must not carry an HR Admin through this one.
 */
const CONFIRM_WORD = 'ERASE';

/**
 * The last door in front of the one action in this system that cannot be
 * undone.
 *
 * It is deliberately heavier than the delete dialog: it says plainly that
 * nothing can be restored afterwards, names every record that is about to be
 * destroyed, says what will be left behind (the activity log entry), and
 * separates out the ones the server will refuse because they have leave on
 * file. Everything it shows, the server checks again.
 */
export function EraseEmployeesDialog({ open, onOpenChange, selected, onErased }: EraseEmployeesDialogProps) {
    const [confirmation, setConfirmation] = useState('');
    const [erasing, setErasing] = useState(false);

    const kept = selected.filter((employee) => (employee.leaveRecordsCount ?? 0) > 0);
    const erasable = selected.filter((employee) => (employee.leaveRecordsCount ?? 0) === 0);
    const confirmed = confirmation.trim().toUpperCase() === CONFIRM_WORD;

    // A fresh dialog always starts with an empty box, so reopening it never
    // arrives already confirmed from last time.
    useEffect(() => {
        if (open) {
            setConfirmation('');
        }
    }, [open]);

    const close = (next: boolean) => {
        if (erasing) {
            return;
        }

        onOpenChange(next);
    };

    const submit = () => {
        if (!confirmed || erasable.length === 0 || erasing) {
            return;
        }

        setErasing(true);

        router.delete(route('employees.erase'), {
            data: { ids: erasable.map((employee) => employee.id) },
            preserveScroll: true,
            onSuccess: () => {
                onErased();
                onOpenChange(false);
            },
            onFinish: () => setErasing(false),
        });
    };

    const count = erasable.length;

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <OctagonAlert className="text-destructive size-5 shrink-0" aria-hidden="true" />
                        {count === 1 ? 'Erase this record for good?' : `Erase ${count} records for good?`}
                    </DialogTitle>
                    <DialogDescription>
                        This cannot be undone. The {count === 1 ? 'row leaves' : 'rows leave'} the database, so there is nothing left to restore and
                        nobody — not even an HR Admin — can bring {count === 1 ? 'it' : 'them'} back.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="border-destructive/40 bg-destructive-surface text-foreground rounded-lg border p-3 text-sm">
                        <p className="font-medium">Erase only a record that should never have existed.</p>
                        <p className="text-muted-foreground mt-1">
                            A duplicate, a test row, a line imported twice. If there is any chance HR will want to look this person up again, leave
                            the record here instead — a deleted record costs nothing and can still be{' '}
                            <strong className="text-foreground">restored</strong>.
                        </p>
                    </div>

                    {count > 0 && (
                        <div>
                            <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">Will be erased ({count})</p>
                            <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto pr-1">
                                {erasable.map((employee) => (
                                    <li key={employee.id} className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="truncate">{employee.listName}</span>
                                        <span className="text-muted-foreground shrink-0 text-xs" data-numeric>
                                            {employee.employeeNo}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-muted-foreground mt-2 text-xs">
                                The activity log keeps a line saying these records were erased, by whom and when. That line is all that will be left.
                            </p>
                        </div>
                    )}

                    {kept.length > 0 && (
                        <div className="bg-muted/50 rounded-lg border p-3">
                            <p className="flex items-center gap-2 text-sm font-medium">
                                <ShieldAlert className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                {kept.length === 1 ? '1 ticked record will be kept' : `${kept.length} ticked records will be kept`}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                They have leave on file, so erasing them would leave a printed tally sheet pointing at nothing. Restore them and
                                deactivate them instead.
                            </p>
                            <ul className="mt-2 max-h-28 space-y-1 overflow-y-auto pr-1">
                                {kept.map((employee) => (
                                    <li key={employee.id} className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="truncate">{employee.listName}</span>
                                        <span className="text-muted-foreground shrink-0 text-xs" data-numeric>
                                            {employee.leaveRecordsCount} {employee.leaveRecordsCount === 1 ? 'day' : 'days'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {count > 0 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="erase-confirmation" className="text-sm">
                                Type <span className="font-semibold">{CONFIRM_WORD}</span> to confirm
                            </Label>
                            <Input
                                id="erase-confirmation"
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                                placeholder={CONFIRM_WORD}
                                autoComplete="off"
                                autoFocus
                                disabled={erasing}
                                className="h-11"
                            />
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => close(false)} disabled={erasing} className="cursor-pointer">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={submit}
                        disabled={!confirmed || count === 0 || erasing}
                        className="cursor-pointer"
                    >
                        {erasing && <Loader2 className="animate-spin" aria-hidden="true" />}
                        {erasing ? 'Erasing…' : count === 1 ? 'Erase permanently' : `Erase ${count} permanently`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
