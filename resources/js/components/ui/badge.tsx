import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-2xs font-semibold transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground shadow-2xs',
                secondary: 'border-border/60 bg-secondary/80 text-secondary-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground shadow-2xs',
                outline: 'border-border/80 text-foreground',
                success: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                warning: 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                danger: 'border-rose-500/25 bg-rose-500/10 text-rose-700 dark:text-rose-300',
                muted: 'border-border/50 bg-muted text-muted-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
