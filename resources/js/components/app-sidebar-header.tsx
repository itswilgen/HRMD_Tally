import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="bg-background/80 sticky top-0 z-10 flex h-14 shrink-0 items-center gap-2 border-b px-4 backdrop-blur-sm md:px-6">
            <SidebarTrigger className="-ml-1.5 cursor-pointer" />
            <div className="bg-border mx-1 h-5 w-px" aria-hidden="true" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />
        </header>
    );
}
