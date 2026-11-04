import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode } from 'react';

interface DataPaginationProps {
    /** The `meta`/`links` block of a paginated resource collection. */
    meta: Paginated<unknown>['meta'];
    links: Paginated<unknown>['links'];
    /** Plural noun for the count, e.g. "accounts". */
    label: string;
    /** Added after the count, e.g. how the list is ordered. */
    note?: string;
}

/**
 * A page step. At the ends of the list it becomes a disabled button rather
 * than disappearing, so the controls do not move under the pointer.
 */
function PageLink({ href, rel, children }: { href: string | null; rel: 'prev' | 'next'; children: ReactNode }) {
    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled className="h-8 rounded-lg opacity-40">
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild className="hover:border-border h-8 rounded-lg font-medium">
            <Link href={href} rel={rel} preserveScroll preserveState>
                {children}
            </Link>
        </Button>
    );
}

export function DataPagination({ meta, links, label, note }: DataPaginationProps) {
    const { from, to, total, current_page: currentPage, last_page: lastPage } = meta;

    return (
        <nav
            aria-label={`${label} pagination`}
            className="border-border/70 bg-muted/20 flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="text-muted-foreground flex items-center gap-2 text-xs" data-numeric>
                {total === 0 ? (
                    <>No {label}</>
                ) : (
                    <>
                        Showing <span className="text-foreground font-semibold">{from}</span>–
                        <span className="text-foreground font-semibold">{to}</span> of <span className="text-foreground font-semibold">{total}</span>{' '}
                        {label}
                        {note !== undefined && <span className="opacity-70"> · {note}</span>}
                    </>
                )}
            </div>

            {lastPage > 1 && (
                <div className="flex items-center gap-2">
                    <span className="bg-muted text-2xs text-muted-foreground rounded-md px-2 py-1 font-semibold" data-numeric>
                        Page {currentPage} of {lastPage}
                    </span>
                    <PageLink href={links.prev} rel="prev">
                        <ChevronLeft className="size-3.5" aria-hidden="true" />
                        Previous
                    </PageLink>
                    <PageLink href={links.next} rel="next">
                        Next
                        <ChevronRight className="size-3.5" aria-hidden="true" />
                    </PageLink>
                </div>
            )}
        </nav>
    );
}
