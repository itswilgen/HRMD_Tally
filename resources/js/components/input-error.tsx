import { cn } from '@/lib/utils';
import { HTMLAttributes } from 'react';

export default function InputError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} role="alert" className={cn('text-destructive text-xs font-medium', className)}>
            {message}
        </p>
    ) : null;
}
