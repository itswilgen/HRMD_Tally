import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    icon: LucideIcon;
    title: string;
    description: string;
    /** What to do next — an empty screen should never be a dead end. */
    action?: ReactNode;
    className?: string;
}

export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center gap-3 px-6 py-14 text-center', className)}>
            <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-lg">
                <Icon className="size-5" aria-hidden="true" />
            </div>
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                <p className="text-muted-foreground mx-auto max-w-[48ch] text-sm">{description}</p>
            </div>
            {action}
        </div>
    );
}
