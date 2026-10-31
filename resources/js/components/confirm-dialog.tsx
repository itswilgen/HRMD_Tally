import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Loader2 } from 'lucide-react';
import { type ReactNode } from 'react';

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    cancelLabel?: string;
    /** `destructive` for anything that removes data. */
    tone?: 'default' | 'destructive';
    processing?: boolean;
    onConfirm: () => void;
}

/**
 * Used for every action that cannot simply be undone by clicking again.
 * The description says what will happen in the office's own terms, not
 * "Are you sure?".
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancel',
    tone = 'default',
    processing = false,
    onConfirm,
}: ConfirmDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription asChild>
                        <div className="space-y-2 text-sm">{description}</div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        {cancelLabel}
                    </Button>
                    <Button type="button" variant={tone === 'destructive' ? 'destructive' : 'default'} onClick={onConfirm} disabled={processing}>
                        {processing && <Loader2 className="animate-spin" aria-hidden="true" />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
