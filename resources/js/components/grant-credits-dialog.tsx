import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { LeaveChip } from '@/components/leave-chip';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { figurePayload, looksLikeFigure } from '@/lib/credits';
import { cn } from '@/lib/utils';
import { type CreditColumn, type OfficeOption } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';

interface GrantCreditsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    year: number;
    /** Only the active leave types: a retired one takes no new grant. */
    columns: CreditColumn[];
    offices: OfficeOption[];
}

const ALL = 'all';

/**
 * "Give everybody this much for the year."
 *
 * The standard yearly grant is the same for nearly everyone, so this fills a
 * blank year in one go; the exceptions are then corrected in the grid. A code
 * left blank here grants nothing for it — it is not a grant of 0 — and figures
 * already on file are kept unless "Replace" is ticked.
 */
export function GrantCreditsDialog({ open, onOpenChange, year, columns, offices }: GrantCreditsDialogProps) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [office, setOffice] = useState(ALL);
    const [overwrite, setOverwrite] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const filled = columns.filter((column) => (values[String(column.id)] ?? '').trim() !== '');
    const invalid = columns.some((column) => !looksLikeFigure(values[String(column.id)] ?? ''));
    const officeLabel =
        office === ALL ? 'everyone on the sheet' : (offices.find((option) => String(option.value) === office)?.label ?? 'that office');

    const reset = () => {
        setValues({});
        setOffice(ALL);
        setOverwrite(false);
        setErrors({});
    };

    const close = (next: boolean) => {
        if (saving) {
            return;
        }

        onOpenChange(next);

        if (!next) {
            reset();
        }
    };

    const submit = () => {
        router.post(
            route('credits.grant'),
            {
                year,
                credits: Object.fromEntries(columns.map((column) => [column.id, figurePayload(values[String(column.id)] ?? '')])),
                office_id: office === ALL ? null : Number(office),
                overwrite,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    onOpenChange(false);
                    reset();
                },
                onError: (received) => setErrors(received),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Grant {year} credits to all</DialogTitle>
                    <DialogDescription>
                        Type the standard yearly figure for each leave code. Leave a code blank to grant nothing for it, then correct the exceptions
                        in the grid.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="grant-credits"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                    className="space-y-5"
                >
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Days per leave code</legend>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {columns.map((column) => {
                                const key = String(column.id);
                                const value = values[key] ?? '';
                                const error = errors[`credits.${key}`];

                                return (
                                    <div key={column.id} className="grid min-w-0 gap-1">
                                        <Label
                                            htmlFor={`grant-${key}`}
                                            className="flex min-w-0 items-center gap-1.5 text-xs font-normal"
                                            title={column.name}
                                        >
                                            <LeaveChip color={column.color} code={column.code} />
                                            <span className="text-muted-foreground min-w-0 truncate">{column.name}</span>
                                        </Label>
                                        <input
                                            id={`grant-${key}`}
                                            type="text"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            value={value}
                                            placeholder="—"
                                            onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))}
                                            aria-invalid={error !== undefined || !looksLikeFigure(value) ? true : undefined}
                                            className={cn(
                                                'border-input bg-card h-11 w-full rounded-md border px-3 text-center font-mono font-semibold tabular-nums',
                                                'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-2 focus-visible:outline-none',
                                                (error !== undefined || !looksLikeFigure(value)) && 'border-destructive bg-destructive-surface',
                                            )}
                                        />
                                        <InputError message={error} />
                                    </div>
                                );
                            })}
                        </div>
                    </fieldset>

                    <FormField id="grant-office" label="Who gets it" error={errors.office_id}>
                        <Select value={office} onValueChange={setOffice}>
                            <SelectTrigger id="grant-office" className="h-11">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Everyone on the {year} sheet</SelectItem>
                                {offices.map((option) => (
                                    <SelectItem key={option.value} value={String(option.value)}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>

                    <div className="flex items-start gap-3 rounded-lg border p-3">
                        <Checkbox
                            id="grant-overwrite"
                            checked={overwrite}
                            onCheckedChange={(checked) => setOverwrite(checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor="grant-overwrite">Replace figures already on file</Label>
                            <p className="text-muted-foreground text-xs">
                                {overwrite
                                    ? 'Every figure for these codes is set to the value above, including ones corrected by hand.'
                                    : 'Unticked, only blank cells are filled; figures already on file are kept.'}
                            </p>
                        </div>
                    </div>

                    <InputError message={errors.year} />
                </form>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => close(false)} disabled={saving} className="cursor-pointer">
                        Cancel
                    </Button>
                    <Button type="submit" form="grant-credits" disabled={saving || filled.length === 0 || invalid} className="cursor-pointer">
                        {saving && <Loader2 aria-hidden="true" className="animate-spin" />}
                        {filled.length === 0 ? 'Type at least one figure' : `Grant to ${officeLabel}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
