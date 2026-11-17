import { cn } from '@/lib/utils';
import { type ReactNode, useEffect, useRef, useState } from 'react';

interface PageHeaderProps {
    /** Small label above the title, e.g. the section the page belongs to. */
    eyebrow?: string;
    title: string;
    description?: string;
    /** Primary action for the page, right-aligned on wide screens. */
    actions?: ReactNode;
    /** Applied to the sticky title bar. */
    className?: string;
}

/** The sticky top bar in AppSidebarHeader is h-14; the title bar sticks right under it. */
const TOP_BAR_HEIGHT_PX = 56;

/**
 * The page title and its actions stay pinned under the top bar, so "Add
 * employee" or "Add leave" is never a scroll away. The eyebrow and description
 * scroll off: they are read once, and keeping them would eat the screen.
 *
 * Rendered as siblings rather than inside one wrapper, because a sticky element
 * only sticks while its parent is on screen. As direct children of the page's
 * <main>, the bar stays pinned for the whole page. The negative margins pull the
 * pieces back together against main's gap-6.
 */
export function PageHeader({ eyebrow, title, description, actions, className }: PageHeaderProps) {
    const bar = useRef<HTMLDivElement>(null);
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        const element = bar.current;

        if (!element) {
            return;
        }

        // The observed edge sits one pixel above where the bar sticks, so the
        // bar reads as "not fully visible" exactly while it is pinned.
        const observer = new IntersectionObserver(
            ([entry]) => setStuck(entry.intersectionRatio < 1 && entry.boundingClientRect.top <= TOP_BAR_HEIGHT_PX + 1),
            { rootMargin: `-${TOP_BAR_HEIGHT_PX + 1}px 0px 0px 0px`, threshold: [1] },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return (
        <>
            {eyebrow && <p className="text-muted-foreground -mb-5 text-xs font-semibold tracking-[0.08em] uppercase">{eyebrow}</p>}

            <div
                ref={bar}
                data-stuck={stuck}
                className={cn(
                    // bg-page so the pinned title bar is the canvas scrolling
                    // under itself, not a white band crossing the page.
                    'bg-page sticky top-14 z-[9] -mx-4 flex flex-col gap-3 border-b border-transparent px-4 py-2.5 md:-mx-6 md:px-6',
                    'data-[stuck=true]:border-border transition-colors duration-150 motion-reduce:transition-none',
                    'sm:flex-row sm:items-center sm:justify-between',
                    className,
                )}
            >
                <h1 className="text-foreground font-display min-w-0 text-[1.5rem] leading-tight font-semibold tracking-[-0.02em] text-balance sm:text-[1.75rem] lg:truncate">
                    {title}
                </h1>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>

            {description && <p className="text-muted-foreground -mt-6 max-w-[70ch] text-sm leading-relaxed">{description}</p>}

            {/* The form rule: the one ornament in the system, and it is a ruled line. */}
            <div aria-hidden="true" className="form-rule -mt-3 h-0.5" />
        </>
    );
}
