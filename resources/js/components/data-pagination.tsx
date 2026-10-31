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
            <Button variant="outline" size="sm" disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} rel={rel} preserveScroll preserveState>
                {children}
            </Link>
        </Button>
    );
}

export function DataPagination({ meta, links, label, note }: DataPaginationProps) {
    const { from, to, total, current_page: currentPage, last_page: lastPage } = meta;

    return (
        <nav aria-label={`${label} pagination`} className="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-muted-foreground text-xs" data-numeric>
                {total === 0 ? (
                    <>No {label}</>
                ) : (
                    <>
                        Showing <span className="text-foreground font-medium">{from}</span>–<span className="text-foreground font-medium">{to}</span>{' '}
                        of <span className="text-foreground font-medium">{total}</span> {label}
                        {note !== undefined && <span> · {note}</span>}
                    </>
                )}
            </p>

            {lastPage > 1 && (
                <div className="flex items-center gap-2">
                    <span className="text-muted-foreground mr-1 text-xs" data-numeric>
                        Page {currentPage} of {lastPage}
                    </span>
                    <PageLink href={links.prev} rel="prev">
                        <ChevronLeft aria-hidden="true" />
                        Previous
                    </PageLink>
                    <PageLink href={links.next} rel="next">
                        Next
                        <ChevronRight aria-hidden="true" />
                    </PageLink>
                </div>
            )}
        </nav>
    );
}
