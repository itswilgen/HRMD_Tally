import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';

interface Toast {
    id: string;
    tone: 'success' | 'error';
    message: string;
}

const DISMISS_AFTER_MS = 6000;

/**
 * Confirmation of what just happened, in the corner of the screen.
 *
 * Errors stay until dismissed; a success notice clears itself, because the
 * encoder is already typing the next record by then.
 */
export function FlashMessages() {
    const { flash } = usePage<SharedData>().props;
    const reduceMotion = useReducedMotion();
    const [toasts, setToasts] = useState<Toast[]>([]);

    useEffect(() => {
        if (!flash.id) {
            return;
        }

        const incoming: Toast[] = [];

        if (flash.success) {
            incoming.push({ id: `${flash.id}-success`, tone: 'success', message: flash.success });
        }

        if (flash.error) {
            incoming.push({ id: `${flash.id}-error`, tone: 'error', message: flash.error });
        }

        setToasts((current) => [...current, ...incoming]);

        // Each success notice owns its own timer, so a second one arriving does
        // not extend the life of the first.
        const timers = incoming
            .filter((toast) => toast.tone === 'success')
            .map((toast) =>
                window.setTimeout(() => {
                    setToasts((current) => current.filter((candidate) => candidate.id !== toast.id));
                }, DISMISS_AFTER_MS),
            );

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [flash.id, flash.success, flash.error]);

    const dismiss = (id: string) => setToasts((current) => current.filter((toast) => toast.id !== id));

    return (
        <div
            aria-live="polite"
            aria-atomic="false"
            className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-6 sm:bottom-6"
        >
            <AnimatePresence initial={false}>
                {toasts.map((toast) => (
                    <motion.div
                        key={toast.id}
                        layout={!reduceMotion}
                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 12, scale: 0.98 }}
                        animate={reduceMotion ? { opacity: 1 } : { opacity: 1, y: 0, scale: 1 }}
                        exit={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 8, scale: 0.98 }}
                        transition={reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 380, damping: 30 }}
                        className="bg-card text-card-foreground pointer-events-auto relative flex w-full max-w-sm items-start gap-3 overflow-hidden rounded-lg border py-3 pr-3 pl-4 shadow-[0_1px_2px_rgba(16,33,30,0.06),0_8px_24px_-8px_rgba(16,33,30,0.18)]"
                    >
                        <span
                            aria-hidden="true"
                            className={
                                toast.tone === 'success'
                                    ? 'bg-success absolute inset-y-0 left-0 w-[3px]'
                                    : 'bg-destructive absolute inset-y-0 left-0 w-[3px]'
                            }
                        />
                        {toast.tone === 'success' ? (
                            <CircleCheck className="text-success mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        ) : (
                            <CircleAlert className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        )}
                        <p className="flex-1 text-sm">{toast.message}</p>
                        <button
                            type="button"
                            onClick={() => dismiss(toast.id)}
                            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring -mt-0.5 -mr-0.5 cursor-pointer rounded-sm p-1 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <X className="size-4" aria-hidden="true" />
                            <span className="sr-only">Dismiss</span>
                        </button>
                    </motion.div>
                ))}
            </AnimatePresence>
        </div>
    );
}
