import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    /** Small label above the title, e.g. the section the page belongs to. */
    eyebrow?: string;
    title: string;
    description?: string;
    /** Primary action for the page, right-aligned on wide screens. */
    actions?: ReactNode;
    className?: string;
}

export function PageHeader({ eyebrow, title, description, actions, className }: PageHeaderProps) {
    return (
        <div className={cn('flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between', className)}>
            <div className="min-w-0 space-y-1">
                {eyebrow && <p className="text-muted-foreground text-2xs font-semibold tracking-[0.12em] uppercase">{eyebrow}</p>}
                <h1 className="text-xl font-semibold">{title}</h1>
                {description && <p className="text-muted-foreground max-w-[65ch] text-sm">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}
