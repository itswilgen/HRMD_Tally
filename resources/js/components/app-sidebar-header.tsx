import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The top bar: breadcrumbs on the left, appearance toggle on the right.
 *
 * The background is opaque on purpose — do not put backdrop-blur back. A
 * translucent sticky bar needs backdrop-filter to stay readable, and a blurred
 * backdrop on a sticky element makes the browser re-sample and re-blur
 * everything behind it on every scroll frame. In Chromium that paints as a
 * flickering band across the top while scrolling. An opaque header cannot
 * smear, costs nothing to composite, and is the flat surface UI_SPEC asks for.
 */
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="bg-page sticky top-0 z-10 flex h-14 shrink-0 items-center justify-between border-b px-4 md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="hover:bg-accent -ml-1.5 cursor-pointer rounded-md p-1.5 transition-colors" />
                <div className="bg-border mx-1 h-5 w-px" aria-hidden="true" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <AppearanceToggleDropdown />
        </header>
    );
}
