import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CircleAlert, Download, FileSpreadsheet, FileText, FolderDown, Loader2 } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect } from 'react';

type ExportStatus = 'queued' | 'running' | 'done' | 'failed';

interface ExportRow {
    id: string;
    kind: 'tally_pdf' | 'tally_xlsx' | 'credits_xlsx';
    title: string;
    fileName: string;
    status: ExportStatus;
    rows: number;
    size: number | null;
    error: string | null;
    createdAt: string | null;
    finishedAt: string | null;
    expiresAt: string | null;
}

interface DownloadsPageProps {
    exports: ExportRow[];
    highlight: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Downloads', href: '/downloads' }];

/** How often a waiting export is checked on. 1,000 employees take about 10 s. */
const POLL_MS = 2000;

const EASE = [0.22, 1, 0.36, 1] as const;

function formatSize(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function formatTime(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatDay(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleDateString('en-PH', { month: 'long', day: 'numeric' });
}

/**
 * A waiting export's only motion: a strip that moves while the worker builds
 * the file. With reduced motion it is a still strip and the words do the job.
 */
function PreparingStrip() {
    const reduceMotion = useReducedMotion();

    return (
        <div className="bg-primary/15 relative h-1 w-full overflow-hidden rounded-full" aria-hidden="true">
            {reduceMotion ? (
                <div className="bg-primary/60 absolute inset-y-0 left-0 w-1/3 rounded-full" />
            ) : (
                <motion.div
                    className="bg-primary absolute inset-y-0 w-1/3 rounded-full"
                    initial={{ x: '-100%' }}
                    animate={{ x: '300%' }}
                    transition={{ duration: 1.4, ease: 'easeInOut', repeat: Infinity }}
                />
            )}
        </div>
    );
}

export default function Downloads({ exports, highlight }: DownloadsPageProps) {
    const reduceMotion = useReducedMotion();
    const waiting = exports.some((item) => item.status === 'queued' || item.status === 'running');

    // Ask again while anything is still being made; stop once all are settled.
    useEffect(() => {
        if (!waiting) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['exports'] });
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [waiting]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Downloads" />

            <PageHeader
                eyebrow="Exports"
                title="Downloads"
                description="Big prints and exports are made in the background and wait here. Only you can download your files, and each is kept for 7 days."
            />

            <section className="bg-card overflow-hidden rounded-lg border" aria-label="Your exports" aria-live="polite">
                {exports.length === 0 ? (
                    <EmptyState
                        icon={FolderDown}
                        title="Nothing here yet"
                        description="When a print or an export is too big to make straight away, for example every office at once, it is prepared here and you download it when it is ready."
                    />
                ) : (
                    <ol className="divide-y">
                        {exports.map((item, index) => {
                            const isPdf = item.kind === 'tally_pdf';
                            const Icon = isPdf ? FileText : FileSpreadsheet;
                            const pending = item.status === 'queued' || item.status === 'running';
                            const isNew = item.id === highlight;

                            return (
                                <motion.li
                                    key={item.id}
                                    initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 4 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={reduceMotion ? { duration: 0 } : { duration: 0.2, delay: Math.min(index, 10) * 0.03, ease: EASE }}
                                    className={cn('flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center', isNew && 'bg-primary/5')}
                                >
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'flex size-9 shrink-0 items-center justify-center rounded-md',
                                                item.status === 'failed' ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            <Icon className="size-4" />
                                        </span>
                                        <div className="grid min-w-0 flex-1 gap-1">
                                            <p className="flex flex-wrap items-center gap-x-2 font-medium">
                                                {item.title}
                                                {isNew && <span className="text-primary text-xs font-semibold">Just asked for</span>}
                                            </p>
                                            <p className="text-muted-foreground truncate font-mono text-xs">{item.fileName}</p>

                                            {pending && (
                                                <div className="grid max-w-sm gap-1.5 pt-1">
                                                    <PreparingStrip />
                                                    <p className="text-muted-foreground text-xs">
                                                        {item.status === 'queued' ? 'Waiting for its turn' : 'Preparing'} ·{' '}
                                                        <span data-numeric>{item.rows.toLocaleString()}</span> employees
                                                    </p>
                                                </div>
                                            )}

                                            {item.status === 'done' && (
                                                <p className="text-muted-foreground text-xs">
                                                    <span data-numeric>{item.rows.toLocaleString()}</span> employees · {formatSize(item.size)} · ready{' '}
                                                    {formatTime(item.finishedAt)} · kept until {formatDay(item.expiresAt)}
                                                </p>
                                            )}

                                            {item.status === 'failed' && (
                                                <p className="text-destructive flex items-start gap-1.5 text-xs" role="alert">
                                                    <CircleAlert aria-hidden="true" className="mt-px size-3.5 shrink-0" />
                                                    {item.error ?? 'The file could not be made. Try again.'}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 justify-end pl-12 sm:pl-0">
                                        {item.status === 'done' && (
                                            <Button asChild variant={isNew ? 'default' : 'outline'} size="sm">
                                                <a
                                                    href={route('downloads.show', item.id)}
                                                    target={isPdf ? '_blank' : undefined}
                                                    rel={isPdf ? 'noopener' : undefined}
                                                >
                                                    <Download aria-hidden="true" />
                                                    {isPdf ? 'Open PDF' : 'Download'}
                                                </a>
                                            </Button>
                                        )}
                                        {pending && (
                                            <span className="text-muted-foreground inline-flex h-9 items-center gap-2 px-3 text-sm">
                                                <Loader2 aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />
                                                Preparing…
                                            </span>
                                        )}
                                    </div>
                                </motion.li>
                            );
                        })}
                    </ol>
                )}
            </section>
        </AppLayout>
    );
}
