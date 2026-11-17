import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';

interface CopyCreditsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The year being filled. */
    year: number;
    years: number[];
}

/**
 * "Start this year from last year's figures."
 *
 * The yearly grant rarely changes, so copying is the fastest honest start.
 * Unless "Replace" is ticked, a figure already on file for this year is kept,
 * because copying is for filling blanks.
 */
export function CopyCreditsDialog({ open, onOpenChange, year, years }: CopyCreditsDialogProps) {
    const sources = years.filter((option) => option !== year);
    const defaultSource = sources.includes(year - 1) ? year - 1 : (sources[0] ?? year - 1);

    const [from, setFrom] = useState(String(defaultSource));
    const [overwrite, setOverwrite] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const close = (next: boolean) => {
        if (saving) {
            return;
        }

        onOpenChange(next);

        if (!next) {
            setFrom(String(defaultSource));
            setOverwrite(false);
            setErrors({});
        }
    };

    const submit = () => {
        router.post(
            route('credits.copy'),
            { year, from_year: Number(from), overwrite },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => close(false),
                onError: (received) => setErrors(received),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Copy credits into {year}</DialogTitle>
                    <DialogDescription>Every figure on file for the year you choose is copied onto {year}, employee by employee.</DialogDescription>
                </DialogHeader>

                <form
                    id="copy-credits"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                    className="space-y-5"
                >
                    <FormField id="copy-from" label="Copy from" error={errors.from_year}>
                        <Select value={from} onValueChange={setFrom}>
                            <SelectTrigger id="copy-from" className="h-11" data-numeric>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {sources.map((option) => (
                                    <SelectItem key={option} value={String(option)}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>

                    <div className="flex items-start gap-3 rounded-lg border p-3">
                        <Checkbox
                            id="copy-overwrite"
                            checked={overwrite}
                            onCheckedChange={(checked) => setOverwrite(checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor="copy-overwrite">Replace {year} figures already on file</Label>
                            <p className="text-muted-foreground text-xs">
                                {overwrite
                                    ? `Figures already typed for ${year} are replaced by the ${from} ones.`
                                    : `Unticked, only blank ${year} cells are filled; anything already typed is kept.`}
                            </p>
                        </div>
                    </div>

                    <InputError message={errors.year} />
                </form>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => close(false)} disabled={saving} className="cursor-pointer">
                        Cancel
                    </Button>
                    <Button type="submit" form="copy-credits" disabled={saving || sources.length === 0} className="cursor-pointer">
                        {saving && <Loader2 aria-hidden="true" className="animate-spin" />}
                        Copy {from} into {year}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
