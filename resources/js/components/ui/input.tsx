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
                'flex h-10 w-full rounded-md border border-input bg-card px-3 py-2 text-sm transition-[border-color,box-shadow] file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground/80 hover:border-muted-foreground/40 focus-visible:border-ring focus-visible:outline-hidden focus-visible:ring-3 focus-visible:ring-ring/20 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20',
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
