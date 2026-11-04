import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-4 sm:p-8">
            {/* Ambient luxury lighting */}
            <div aria-hidden="true" className="bg-primary/10 pointer-events-none absolute -top-40 -left-40 size-96 rounded-full blur-3xl" />
            <div aria-hidden="true" className="pointer-events-none absolute -right-40 -bottom-40 size-96 rounded-full bg-emerald-500/10 blur-3xl" />

            <div className="relative w-full max-w-md">
                <div className="border-border/80 bg-card/95 rounded-2xl border p-6 shadow-xl backdrop-blur-md sm:p-8">
                    <div className="flex flex-col items-center gap-3 text-center">
                        <Link href="/" className="group flex flex-col items-center gap-2 font-medium">
                            <div className="from-primary/20 via-primary/10 ring-primary/25 flex size-12 items-center justify-center rounded-xl bg-gradient-to-br to-transparent shadow-xs ring-1 transition-transform group-hover:scale-105">
                                <AppLogoIcon className="text-primary size-7" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="border-primary/20 bg-primary/10 text-2xs text-primary inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-bold tracking-wider uppercase">
                            <span className="bg-primary size-1.5 rounded-full" />
                            HRMD Leave Tally System
                        </div>

                        <div className="space-y-1">
                            <h1 className="text-foreground text-xl font-bold tracking-tight sm:text-2xl">{title}</h1>
                            {description && <p className="text-muted-foreground text-xs">{description}</p>}
                        </div>
                    </div>

                    <div className="mt-6">{children}</div>

                    <div className="border-border/60 text-2xs text-muted-foreground mt-6 flex items-center justify-center gap-1.5 border-t pt-4 text-center">
                        <ShieldCheck className="text-primary size-3.5" aria-hidden="true" />
                        <span>Official HRMD Portal · RA 10173 Protected</span>
                    </div>
                </div>
            </div>
        </div>
    );
}
