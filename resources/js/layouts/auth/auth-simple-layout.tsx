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
 * own look: the lavender-to-blue ground with the banner's network drifting
 * behind one centered column.
 *
 * The brand (the City of Tanjay seal, "HRMD", "LGU Tanjay City") sits above
 * the sheet like a letterhead above a form, so the eye lands on who this is
 * and then on what to do. Centering is right here and nowhere else in the
 * system: the page holds one short brand line and one form, nothing to scan.
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

            <main className="short:py-5 relative mx-auto flex min-h-svh w-full max-w-[26rem] flex-col justify-center px-4 py-8">
                <motion.header {...rise(0)} className="short:mb-4 mb-6 flex flex-col items-center text-center">
                    <Link
                        href="/"
                        aria-label="HRMD, LGU Tanjay City — Leave Tally Sheet"
                        className="flex flex-col items-center rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4"
                    >
                        <TanjaySeal size={80} alt="" className="drop-shadow-seal short:size-14 size-[4.5rem] sm:size-20" />
                        <span className="font-display text-landing-ink short:mt-3 short:text-4xl mt-4 text-5xl leading-none font-bold tracking-[-0.03em]">
                            HRMD
                        </span>
                        <span className="font-display text-landing-ink mt-2 text-sm font-bold tracking-[0.18em] uppercase sm:text-base">
                            LGU Tanjay City
                        </span>
                    </Link>
                    <p className="text-landing-muted short:hidden mt-2 text-sm">Leave Tally Sheet · Human Resource Management Division</p>
                </motion.header>

                <motion.div {...rise(0.08)}>
                    <div className="bg-card shadow-landing short:sm:p-6 rounded-xl border p-6 sm:p-8">
                        <div className="form-rule short:mb-4 mb-6 space-y-1 pb-4">
                            <h1 className="text-foreground font-display text-[1.375rem] font-semibold tracking-[-0.02em]">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm">{description}</p>}
                        </div>

                        {children}
                    </div>

                    <p className="text-landing-muted mx-auto mt-5 max-w-[38ch] text-center text-xs leading-relaxed">
                        For authorised HRMD staff. Employee records are personal information protected under the Data Privacy Act of 2012 (RA 10173).
                    </p>
                </motion.div>
            </main>
        </div>
    );
}
