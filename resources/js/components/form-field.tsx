import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface FormFieldProps {
    id: string;
    label: string;
    /** Shown under the label: what to type, or why the field exists. */
    hint?: string;
    error?: string;
    optional?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * One labelled control. The hint sits above the input and the error below it,
 * next to the field it belongs to rather than in a summary at the top.
 */
export function FormField({ id, label, hint, error, optional = false, className, children }: FormFieldProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <Label htmlFor={id}>{label}</Label>
                {optional && <span className="text-muted-foreground text-2xs">Optional</span>}
            </div>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            {children}
            <InputError message={error} />
        </div>
    );
}
