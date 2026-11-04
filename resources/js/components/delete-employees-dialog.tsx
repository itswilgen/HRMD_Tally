import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type EmployeeRecord } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2, ShieldAlert, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

interface DeleteEmployeesDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The ticked rows, in the order the table shows them. */
    selected: EmployeeRecord[];
    /** Clears the tick-boxes once the delete has gone through. */
    onDeleted: () => void;
}

/** Typed to confirm. Compared case-insensitively — HR staff are not typists. */
const CONFIRM_WORD = 'DELETE';

/**
 * The warning in front of a bulk delete.
 *
 * It does three things before it will enable the button: says plainly what
 * "delete" means here (recoverable, not erased), names every employee that is
 * about to go rather than only counting them, and separates out the ones the
 * server will keep because they already have leave on file. The typed
 * confirmation is there so the action cannot happen on a mis-click after the
 * tick-boxes are already set.
 */
export function DeleteEmployeesDialog({ open, onOpenChange, selected, onDeleted }: DeleteEmployeesDialogProps) {
    const [confirmation, setConfirmation] = useState('');
    const [deleting, setDeleting] = useState(false);

    const kept = selected.filter((employee) => (employee.leaveRecordsCount ?? 0) > 0);
    const removable = selected.filter((employee) => (employee.leaveRecordsCount ?? 0) === 0);
    const confirmed = confirmation.trim().toUpperCase() === CONFIRM_WORD;

    // A fresh dialog always starts with an empty box, so reopening it never
    // arrives already confirmed from last time.
    useEffect(() => {
        if (open) {
            setConfirmation('');
        }
    }, [open]);

    const close = (next: boolean) => {
        if (deleting) {
            return;
        }

        onOpenChange(next);
    };

    const submit = () => {
        if (!confirmed || removable.length === 0 || deleting) {
            return;
        }

        setDeleting(true);

        router.delete(route('employees.bulk-destroy'), {
            data: { ids: removable.map((employee) => employee.id) },
            preserveScroll: true,
            onSuccess: () => {
                onDeleted();
                onOpenChange(false);
            },
            onFinish: () => setDeleting(false),
        });
    };

    const count = removable.length;

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <TriangleAlert className="text-destructive size-5 shrink-0" aria-hidden="true" />
                        {count === 1 ? 'Delete this employee?' : `Delete ${count} employees?`}
                    </DialogTitle>
                    <DialogDescription>
                        They will be removed from the masterlist and from leave encoding. Nothing is erased — an HR Admin can restore the record, and
                        it stays in the activity log either way.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="border-destructive/30 bg-destructive-surface text-foreground rounded-lg border p-3 text-sm">
                        <p className="font-medium">Delete is for a record created by mistake.</p>
                        <p className="text-muted-foreground mt-1">
                            Someone who has left the city government should be <strong className="text-foreground">deactivated</strong> instead, from
                            their edit page. A deactivated employee keeps appearing on the tally sheets they are already on.
                        </p>
                    </div>

                    {count > 0 && (
                        <div>
                            <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">Will be deleted ({count})</p>
                            <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto pr-1">
                                {removable.map((employee) => (
                                    <li key={employee.id} className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="truncate">{employee.listName}</span>
                                        <span className="text-muted-foreground shrink-0 text-xs" data-numeric>
                                            {employee.employeeNo}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {kept.length > 0 && (
                        <div className="bg-muted/50 rounded-lg border p-3">
                            <p className="flex items-center gap-2 text-sm font-medium">
                                <ShieldAlert className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                {kept.length === 1 ? '1 ticked employee will be kept' : `${kept.length} ticked employees will be kept`}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                They have leave on file, so deleting them would leave a printed tally sheet pointing at nothing. Deactivate them
                                instead.
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
                            <Label htmlFor="delete-confirmation" className="text-sm">
                                Type <span className="font-semibold">{CONFIRM_WORD}</span> to confirm
                            </Label>
                            <Input
                                id="delete-confirmation"
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                                placeholder={CONFIRM_WORD}
                                autoComplete="off"
                                autoFocus
                                disabled={deleting}
                                className="h-11"
                            />
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => close(false)} disabled={deleting} className="cursor-pointer">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={submit}
                        disabled={!confirmed || count === 0 || deleting}
                        className="cursor-pointer"
                    >
                        {deleting && <Loader2 className="animate-spin" aria-hidden="true" />}
                        {deleting ? 'Deleting…' : count === 1 ? 'Delete employee' : `Delete ${count} employees`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
