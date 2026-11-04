import * as React from 'react';

import { cn } from '@/lib/utils';

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, onClick, ...props }, ref) => {
    return (
        <input
            type={type}
            onClick={(e) => {
                if (type === 'date') {
                    try {
                        e.currentTarget.showPicker?.();
                    } catch {
                        // ignore if showPicker is unsupported or already open
                    }
                }
                onClick?.(e);
            }}
            className={cn(
                'flex h-10 w-full rounded-lg border border-border/80 bg-background px-3.5 py-2 text-sm shadow-xs ring-offset-background transition-all file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground/70 focus-visible:border-primary/60 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring/20 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50',
                type === 'date' && 'relative pr-10 cursor-pointer',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});

Input.displayName = 'Input';

export { Input };
