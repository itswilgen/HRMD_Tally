import { NetworkBackground } from '@/components/network-background';
import { TanjaySeal } from '@/components/tanjay-seal';
import { Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The landing screen — sign-in and the password pages — in the HRMD banner's
 * own look: the City of Tanjay seal and "HRMD / LGU Tanjay City" set large on
 * the lavender-to-blue ground, with the banner's network drifting behind.
 *
 * The brand side is the only place in the system allowed to be this loud.
 * The sheet on the right stays plain and readable: it is still a form.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    const reduceMotion = useReducedMotion();
    const rise = (delay: number) =>
        reduceMotion
            ? { initial: { opacity: 0 }, animate: { opacity: 1 }, transition: { duration: 0.2 } }
            : {
                  initial: { opacity: 0, y: 12 },
                  animate: { opacity: 1, y: 0 },
                  transition: { duration: 0.45, delay, ease: [0.22, 1, 0.36, 1] as const },
              };

    return (
        <div className="landing-canvas relative isolate min-h-svh overflow-hidden">
            <NetworkBackground />

            <div className="relative mx-auto grid min-h-svh w-full max-w-6xl items-center gap-8 px-4 py-10 sm:px-8 lg:grid-cols-[minmax(0,1fr)_25rem] lg:gap-16">
                <motion.header {...rise(0)} className="flex flex-col items-start gap-5 lg:gap-7">
                    <Link
                        href="/"
                        className="flex items-center gap-4 rounded-full focus-visible:outline-2 focus-visible:outline-offset-4 lg:flex-col lg:items-start lg:gap-7"
                    >
                        <TanjaySeal size={112} className="drop-shadow-seal size-16 sm:size-20 lg:size-28" />
                        <span className="grid">
                            <span className="font-display text-landing-ink text-5xl leading-[0.95] font-bold tracking-[-0.03em] sm:text-6xl lg:text-8xl">
                                HRMD
                            </span>
                            <span className="font-display text-landing-ink mt-1 text-base font-bold tracking-[0.12em] uppercase sm:text-lg lg:mt-3 lg:text-2xl">
                                LGU Tanjay City
                            </span>
                        </span>
                    </Link>

                    <p className="text-landing-muted hidden max-w-[42ch] text-base leading-relaxed sm:block">
                        Leave Tally Sheet of the Human Resource Management Division — encode leave, read the yearly sheet, and keep leave credits in
                        one place.
                    </p>
                </motion.header>

                <motion.div {...rise(0.08)} className="w-full">
                    <div className="bg-card shadow-landing rounded-xl border p-6 sm:p-8">
                        <div className="form-rule mb-6 space-y-1 pb-4">
                            <h1 className="text-foreground font-display text-[1.375rem] font-semibold tracking-[-0.02em]">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm">{description}</p>}
                        </div>

                        {children}
                    </div>

                    <p className="text-landing-muted mt-5 text-xs leading-relaxed">
                        For authorised HRMD staff. Employee records are personal information protected under the Data Privacy Act of 2012 (RA 10173).
                    </p>
                </motion.div>
            </div>
        </div>
    );
}
